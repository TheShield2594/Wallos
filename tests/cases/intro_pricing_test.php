<?php
/*
  Free trials and intro prices (includes/intro_pricing.php).
*/

require_once WALLOS_ROOT . '/includes/intro_pricing.php';
require_once WALLOS_ROOT . '/includes/budget_period_calculations.php';

function intro_sub(array $overrides = [])
{
    return array_merge([
        'id' => 1,
        'user_id' => 1,
        'name' => 'Streaming',
        'price' => 12.0,
        'currency_id' => 1,
        'cycle' => 3,
        'frequency' => 1,
        'next_payment' => '2026-12-04',
        'start_date' => '2026-11-04',
        'auto_renew' => 1,
        'inactive' => 0,
        'intro_type' => 'trial',
        'intro_price' => null,
        'intro_end_date' => '2026-12-04',
        'intro_notify_days' => 3,
        'intro_notified_for' => null,
        'intro_converted_on' => null,
    ], $overrides);
}

wallos_test('a payment costs the intro price before the end date and the regular price from it', function () {
    $promo = intro_sub(['intro_type' => 'promo', 'intro_price' => 1.0, 'next_payment' => '2026-11-04', 'intro_end_date' => '2027-02-04']);

    assert_same(1.0, wallos_price_on($promo, '2027-01-04'), 'inside the promo');
    assert_same(12.0, wallos_price_on($promo, '2027-02-04'), 'the end date is the first payment at the regular price');
    assert_same(0.0, wallos_price_on(intro_sub(), '2026-11-20'), 'a trial is free');
    assert_same(12.0, wallos_price_on(intro_sub(['intro_type' => null]), '2026-11-20'), 'no intro, regular price');
});

wallos_test('occurrences in a range are priced one by one', function () {
    $promo = intro_sub(['intro_type' => 'promo', 'intro_price' => 1.0, 'next_payment' => '2026-11-04', 'intro_end_date' => '2027-01-04']);
    $dates = wallos_occurrences_in_range($promo, new DateTime('2026-11-01'), new DateTime('2027-02-28'));

    assert_same(4, count($dates), 'Nov, Dec, Jan, Feb');
    assert_same(1.0 + 1.0 + 12.0 + 12.0, wallos_price_of_occurrences($promo, $dates), 'two promo payments, then two regular');
});

wallos_test('the pay-period budget charges intro payments at the intro price', function () {
    $GLOBALS['intro_test_db'] = $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    $trial = intro_sub([
        'currency_id' => wallos_test_currency_id(1, 0),
        'next_payment' => (new DateTime('today'))->modify('+40 days')->format('Y-m-d'),
        'intro_end_date' => (new DateTime('today'))->modify('+40 days')->format('Y-m-d'),
        'cycle' => 2,
    ]);

    $amount = computeAmountNeededInPeriod([$trial], new DateTime('today'), (new DateTime('today'))->modify('+60 days'), $db, 1);
    // Weekly from day 40: days 40, 47, 54 -> 3 regular payments, the trial ones before were free.
    assert_equals(36.0, $amount, 'only payments from the end of the trial cost money');
    $db->close();
});

wallos_test('payment reminders are suppressed up to the end of the intro period', function () {
    assert_true(wallos_intro_suppresses_payment_reminder(intro_sub()), 'the first paid payment of a trial gets the trial reminder instead');
    assert_true(!wallos_intro_suppresses_payment_reminder(intro_sub(['next_payment' => '2027-01-04'])), 'payments after it are reminded as usual');
    assert_true(!wallos_intro_suppresses_payment_reminder(intro_sub(['intro_type' => null])), 'no intro, no suppression');
});

wallos_test('the trial-ending reminder is due N days before the end, once', function () {
    $trial = intro_sub(); // ends 2026-12-04, 3 days notice

    assert_true(!wallos_intro_ending_due($trial, '2026-11-30'), 'not before the window');
    assert_true(wallos_intro_ending_due($trial, '2026-12-01'), 'from N days before');
    assert_true(wallos_intro_ending_due($trial, '2026-12-03'), 'and after a missed cron day');
    assert_true(!wallos_intro_ending_due($trial, '2026-12-04'), 'not on the end date: it has converted');
    assert_true(!wallos_intro_ending_due(array_merge($trial, ['intro_notified_for' => '2026-12-04']), '2026-12-02'), 'not again once sent');
    assert_true(!wallos_intro_ending_due(array_merge($trial, ['inactive' => 1]), '2026-12-02'), 'not for a cancelled trial');
});

wallos_test('ended intro periods convert to the regular price and are announced once', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    $stmt = $db->prepare("INSERT INTO subscriptions (user_id, name, price, currency_id, next_payment, cycle, frequency, inactive, intro_type, intro_price, intro_end_date)
        VALUES (1, :name, 12, :currencyId, '2026-12-04', 3, 1, :inactive, :type, :price, :end)");
    foreach ([
        ['Ended trial', 0, 'trial', null, '2026-12-04'],
        ['Running promo', 0, 'promo', 1.0, '2027-01-04'],
        ['Cancelled trial', 1, 'trial', null, '2026-12-01'],
    ] as [$name, $inactive, $type, $price, $end]) {
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->bindValue(':currencyId', wallos_test_currency_id(1, 0), SQLITE3_INTEGER);
        $stmt->bindValue(':inactive', $inactive, SQLITE3_INTEGER);
        $stmt->bindValue(':type', $type, SQLITE3_TEXT);
        $stmt->bindValue(':price', $price, $price === null ? SQLITE3_NULL : SQLITE3_FLOAT);
        $stmt->bindValue(':end', $end, SQLITE3_TEXT);
        $stmt->execute();
    }

    assert_same(1, wallos_convert_ended_intros($db, '2026-12-04'), 'only the active, ended one converts');
    $rows = [];
    $result = $db->query('SELECT name, intro_type, intro_converted_on FROM subscriptions ORDER BY name');
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[$row['name']] = $row;
    }
    assert_same(null, $rows['Ended trial']['intro_type'], 'the intro is cleared');
    assert_same('2026-12-04', $rows['Ended trial']['intro_converted_on'], 'the conversion day is kept for the notice');
    assert_same('promo', $rows['Running promo']['intro_type'], 'a running promo stays');
    assert_same('trial', $rows['Cancelled trial']['intro_type'], 'a cancelled trial keeps its intro (it is a saving)');

    $converted = intro_sub(['intro_type' => null, 'intro_end_date' => null, 'intro_converted_on' => '2026-12-04']);
    assert_true(wallos_intro_converted_due($converted, '2026-12-05'), 'the conversion is announced');
    assert_true(!wallos_intro_converted_due(array_merge($converted, ['intro_notified_for' => 'converted:2026-12-04']), '2026-12-05'), 'once');
    assert_true(!wallos_intro_converted_due($converted, '2026-12-20'), 'and not weeks later');
    $db->close();
});

wallos_test('the renewal cron records intro cycles at the intro price before converting', function () {
    require_once WALLOS_ROOT . '/includes/payment_ledger.php';
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    $db->exec("INSERT INTO subscriptions (id, user_id, name, price, currency_id, next_payment, start_date, cycle, frequency, auto_renew, inactive, intro_type, intro_price, intro_end_date)
        VALUES (7, 1, 'Promo', 12, " . wallos_test_currency_id(1, 0) . ", '2026-10-04', '2026-10-04', 3, 1, 1, 0, 'promo', 1.5, '2026-12-04')");
    $subscription = wallos_ledger_load_subscription($db, 1, 7);

    $advance = wallos_apply_schedule_advance($db, $subscription, new DateTime('2026-12-10'));
    assert_true($advance['success'], 'advanced');
    $amounts = [];
    $result = $db->query('SELECT due_date, amount FROM subscription_payments WHERE subscription_id = 7 ORDER BY due_date');
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $amounts[$row['due_date']] = $row['amount'];
    }
    assert_same(['2026-10-04' => 1.5, '2026-11-04' => 1.5, '2026-12-04' => 12.0], $amounts,
        'promo cycles at the promo price, the end date at the regular price');
    $db->close();
});

wallos_test('save input works out the end date from cycles and moves a trial to its first paid date', function () {
    $schedule = ['cycle' => 3, 'frequency' => 1, 'start_date' => '2099-01-31', 'next_payment' => '2099-01-31'];

    $trial = wallos_intro_fields_from_input(['intro_type' => 'trial', 'intro_length_mode' => 'cycles', 'intro_cycles' => '1'], $schedule);
    assert_same(null, $trial['error'], 'valid');
    assert_same('2099-02-28', $trial['fields']['intro_end_date'], 'one month from Jan 31 clamps to Feb 28');
    assert_same('2099-02-28', $trial['next_payment'], 'the trial moves next_payment to its first paid date');

    $promo = wallos_intro_fields_from_input(['intro_type' => 'promo', 'intro_length_mode' => 'date', 'intro_end_date' => '2099-06-01', 'intro_price' => '2.5'], $schedule);
    assert_same(['intro_type' => 'promo', 'intro_price' => 2.5, 'intro_end_date' => '2099-06-01', 'intro_notify_days' => 3], $promo['fields'], 'promo fields');
    assert_same(null, $promo['next_payment'], 'a promo keeps next_payment');

    assert_same(null, wallos_intro_fields_from_input(['intro_type' => 'none'], $schedule)['fields']['intro_type'], 'none clears it');
    assert_same(null, wallos_intro_fields_from_input(['intro_type' => 'trial', 'intro_end_date' => '2099-06-01'], array_merge($schedule, ['cycle' => 5]))['fields']['intro_type'],
        'one-time payments have no intro');
    assert_true(wallos_intro_fields_from_input(['intro_type' => 'promo', 'intro_end_date' => '2099-06-01', 'intro_price' => '-1'], $schedule)['error'] !== null, 'negative price');
    assert_true(wallos_intro_fields_from_input(['intro_type' => 'promo', 'intro_end_date' => 'soon', 'intro_price' => '1'], $schedule)['error'] !== null, 'bad date');
    assert_true(wallos_intro_fields_from_input(['intro_type' => 'gift'], $schedule)['error'] !== null, 'unknown type');
});
