<?php
/*
  Cancellation notice (includes/cancellation_deadline.php): fixed dates,
  N days/weeks/months before each renewal, minimum terms, and the reminder
  being sent once even when the cron misses days.
*/

require_once WALLOS_ROOT . '/includes/cancellation_deadline.php';

function cancel_sub(array $overrides = [])
{
    return array_merge([
        'id' => 1,
        'user_id' => 1,
        'name' => 'Phone contract',
        'price' => 30,
        'currency_id' => 1,
        'cycle' => 4,
        'frequency' => 1,
        'next_payment' => '2027-03-31',
        'start_date' => '2025-03-31',
        'billing_anchor_day' => 31,
        'inactive' => 0,
        'auto_renew' => 1,
        'cancellation_date' => null,
        'cancel_notice_mode' => 'before_renewal',
        'cancel_notice_value' => 1,
        'cancel_notice_unit' => 'months',
        'min_term_end' => null,
        'last_cancel_notified_for' => null,
        'end_date' => null,
    ], $overrides);
}

wallos_test('cancel-by is the notice period before the next renewal, months clamped', function () {
    $deadline = wallos_cancel_deadline(cancel_sub());
    assert_same('2027-02-28', $deadline['cancel_by'], 'Mar 31 minus one month clamps to Feb 28');
    assert_same('2027-03-31', $deadline['renewal'], 'the renewal it is about');

    assert_same('2027-03-17', wallos_cancel_deadline(cancel_sub(['cancel_notice_value' => 2, 'cancel_notice_unit' => 'weeks']))['cancel_by'],
        'weeks count back in sevens');
    assert_same('2027-03-01', wallos_cancel_deadline(cancel_sub(['cancel_notice_value' => 30, 'cancel_notice_unit' => 'days']))['cancel_by'],
        'days count back day by day');
});

wallos_test('cancel-by moves on when the renewal cron advances next_payment', function () {
    require_once WALLOS_ROOT . '/includes/recurrence.php';
    $subscription = cancel_sub(['next_payment' => '2026-03-31']);
    $before = wallos_cancel_deadline($subscription)['cancel_by'];

    $advance = wallos_advance_schedule($subscription, new DateTime('2026-04-01'));
    $subscription['next_payment'] = $advance['next_payment'];

    assert_same('2026-02-28', $before, 'deadline for this year');
    assert_same('2027-02-28', wallos_cancel_deadline($subscription)['cancel_by'], 'next year after the renewal');
});

wallos_test('a minimum term pushes the deadline to the first renewal after it and locks the row', function () {
    $subscription = cancel_sub(['next_payment' => '2026-03-31', 'min_term_end' => '2027-03-31']);

    $deadline = wallos_cancel_deadline($subscription);
    assert_same('2027-03-31', $deadline['renewal'], 'the renewal on the term end is the first that can be avoided');
    assert_same('2027-02-28', $deadline['cancel_by'], 'notice before that renewal');
    assert_same('2027-03-31', wallos_cancel_locked_until($subscription), 'locked in while the next renewal is inside the term');

    $subscription['next_payment'] = '2027-03-31';
    assert_same(null, wallos_cancel_locked_until($subscription), 'no longer locked once the term is reached');
});

wallos_test('no deadline for no notice, disabled rows, one-time payments or a plan that ends first', function () {
    assert_same(null, wallos_cancel_deadline(cancel_sub(['cancel_notice_mode' => 'none'])), 'none');
    assert_same(null, wallos_cancel_deadline(cancel_sub(['inactive' => 1])), 'disabled');
    assert_same(null, wallos_cancel_deadline(cancel_sub(['cycle' => 5])), 'one-time');
    assert_same(null, wallos_cancel_deadline(cancel_sub(['end_date' => '2027-01-01'])), 'the plan ends before the renewal');
    assert_same(null, wallos_cancel_deadline(cancel_sub(['cancel_notice_value' => null])), 'a notice without a period');
});

wallos_test('a fixed date keeps the original behaviour, including rows saved before the mode existed', function () {
    $fixed = cancel_sub(['cancel_notice_mode' => 'fixed', 'cancellation_date' => '2026-12-01']);
    assert_same(['cancel_by' => '2026-12-01', 'renewal' => null, 'mode' => 'fixed'], wallos_cancel_deadline($fixed), 'fixed date');

    $legacy = cancel_sub(['cancel_notice_mode' => null, 'cancellation_date' => '2026-12-01']);
    assert_same('fixed', wallos_cancel_mode($legacy), 'a stored date without a mode is a fixed date');
    assert_same('none', wallos_cancel_mode(cancel_sub(['cancel_notice_mode' => null])), 'no date, no mode');
});

wallos_test('the reminder is due from the cancel-by date until the renewal, once', function () {
    $subscription = cancel_sub(); // cancel by 2027-02-28, renews 2027-03-31

    assert_same(null, wallos_cancellation_due($subscription, '2027-02-27'), 'not before the cancel-by date');
    assert_true(wallos_cancellation_due($subscription, '2027-02-28') !== null, 'due on the cancel-by date');
    assert_true(wallos_cancellation_due($subscription, '2027-03-05') !== null, 'still due after a missed cron day');
    assert_same(null, wallos_cancellation_due($subscription, '2027-03-31'), 'not once the renewal has happened');

    $subscription['last_cancel_notified_for'] = '2027-02-28';
    assert_same(null, wallos_cancellation_due($subscription, '2027-03-01'), 'not again once sent');
});

wallos_test('a fixed reminder is due for a week after its date', function () {
    $fixed = cancel_sub(['cancel_notice_mode' => 'fixed', 'cancellation_date' => '2026-12-01']);
    assert_true(wallos_cancellation_due($fixed, '2026-12-01') !== null, 'on the day');
    assert_true(wallos_cancellation_due($fixed, '2026-12-08') !== null, 'within the grace week');
    assert_same(null, wallos_cancellation_due($fixed, '2026-12-09'), 'not after it');
});

wallos_test('save input is validated and cleared for the modes that do not use it', function () {
    $result = wallos_cancel_fields_from_input(['cancel_notice_mode' => 'before_renewal', 'cancel_notice_value' => '3', 'cancel_notice_unit' => 'months',
        'cancellation_date' => '2026-01-01', 'min_term_end' => '2027-05-01'], 4);
    assert_same(null, $result['error'], 'valid');
    assert_same(['cancel_notice_mode' => 'before_renewal', 'cancellation_date' => null, 'cancel_notice_value' => 3,
        'cancel_notice_unit' => 'months', 'min_term_end' => '2027-05-01'], $result['fields'], 'the fixed date is dropped');

    assert_same('fixed', wallos_cancel_fields_from_input(['cancellation_date' => '2026-01-01'], 3)['fields']['cancel_notice_mode'],
        'an older client sending only a date gets a fixed reminder');
    assert_same('none', wallos_cancel_fields_from_input(['cancel_notice_mode' => 'fixed', 'cancellation_date' => '2026-01-01'], 5)['fields']['cancel_notice_mode'],
        'one-time payments have no reminder');
    assert_true(wallos_cancel_fields_from_input(['cancel_notice_mode' => 'before_renewal', 'cancel_notice_value' => '-1', 'cancel_notice_unit' => 'days'], 3)['error'] !== null,
        'a negative notice is rejected');
    assert_true(wallos_cancel_fields_from_input(['cancel_notice_mode' => 'before_renewal', 'cancel_notice_value' => '1', 'cancel_notice_unit' => 'years'], 3)['error'] !== null,
        'an unknown unit is rejected');
    assert_true(wallos_cancel_fields_from_input(['cancel_notice_mode' => 'sometimes'], 3)['error'] !== null,
        'an unknown mode is rejected');
});

wallos_test('the dashboard list and the cron both pick up before-renewal notices', function () {
    require_once WALLOS_ROOT . '/includes/upcoming_cancellations.php';

    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    $today = new DateTime('today');
    $renewal = (clone $today)->modify('+20 days')->format('Y-m-d');

    $stmt = $db->prepare("INSERT INTO subscriptions (user_id, name, price, currency_id, next_payment, start_date, cycle, frequency, inactive,
            cancel_notice_mode, cancel_notice_value, cancel_notice_unit, cancellation_date)
        VALUES (1, :name, 10, :currencyId, :next, '2020-01-01', 4, 1, 0, :mode, :value, 'days', :date)");
    foreach ([
        ['Gym', 'before_renewal', 30, null],      // cancel by 10 days ago: missed, not upcoming, but due for the cron
        ['Insurance', 'before_renewal', 10, null], // cancel by in 10 days
        ['Magazine', 'fixed', null, (clone $today)->modify('+3 days')->format('Y-m-d')],
        ['Cloud', 'none', null, null],
    ] as [$name, $mode, $value, $date]) {
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->bindValue(':currencyId', wallos_test_currency_id(1, 0), SQLITE3_INTEGER);
        $stmt->bindValue(':next', $renewal, SQLITE3_TEXT);
        $stmt->bindValue(':mode', $mode, SQLITE3_TEXT);
        $stmt->bindValue(':value', $value, $value === null ? SQLITE3_NULL : SQLITE3_INTEGER);
        $stmt->bindValue(':date', $date, $date === null ? SQLITE3_NULL : SQLITE3_TEXT);
        $stmt->execute();
    }

    $upcoming = get_upcoming_cancellations($db, 1);
    assert_same(['Magazine', 'Insurance'], array_column($upcoming, 'name'), 'future deadlines only, soonest first');
    assert_same((clone $today)->modify('+10 days')->format('Y-m-d'), $upcoming[1]['cancellation_date'], 'the row carries its cancel-by date');
    assert_same($renewal, $upcoming[1]['renewal'], 'and the renewal it avoids');

    assert_same(['Gym'], cancellation_cron_names($db, 1), 'the cron reminds the passed deadline before the renewal');

    $db->close();
});

wallos_test('the migration turns stored dates into fixed reminders without resending old ones', function () {
    $source = file_get_contents(WALLOS_ROOT . '/migrations/000064.php');
    assert_contains("SET cancel_notice_mode = 'fixed'", $source, 'stored dates become fixed reminders');
    assert_contains('SET last_cancel_notified_for = cancellation_date', $source, 'past dates count as reminded');
});
