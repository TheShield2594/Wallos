<?php
/*
  The renewal cron advances each account's payments on the account's own
  calendar (includes/renewal_cron.php): the local date in its notification
  time zone, or the container's when it has none. A payment due on the 10th
  in Los Angeles stays the next payment until the 10th is over there, so a
  late day-of reminder (23:30 local) still sees it.
*/

require_once WALLOS_ROOT . '/includes/renewal_cron.php';

function renewal_tz_database()
{
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'utc');
    wallos_test_create_user($db, 2, 'la');
    wallos_test_create_user($db, 3, 'tokyo');
    $settings = $db->prepare('INSERT INTO notification_settings (user_id, days, send_time, timezone) VALUES (:userId, 1, :sendTime, :timezone)');
    foreach ([[2, '23:30', 'America/Los_Angeles'], [3, '09:00', 'Asia/Tokyo']] as [$userId, $sendTime, $timezone]) {
        $settings->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $settings->bindValue(':sendTime', $sendTime, SQLITE3_TEXT);
        $settings->bindValue(':timezone', $timezone, SQLITE3_TEXT);
        $settings->execute();
        $settings->reset();
    }

    return $db;
}

function renewal_tz_subscription(SQLite3 $db, $userId, $name, $nextPayment, array $extra = [])
{
    $row = array_merge([
        'user_id' => $userId,
        'name' => $name,
        'price' => 10.0,
        'currency_id' => wallos_test_currency_id($userId, 0),
        'next_payment' => $nextPayment,
        'cycle' => 3,
        'frequency' => 1,
        'auto_renew' => 1,
        'inactive' => 0,
        'notify' => 1,
    ], $extra);
    $columns = array_keys($row);
    $stmt = $db->prepare('INSERT INTO subscriptions (' . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')');
    foreach ($row as $column => $value) {
        $stmt->bindValue(':' . $column, $value, $value === null ? SQLITE3_NULL : (is_float($value) ? SQLITE3_FLOAT : (is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT)));
    }
    $stmt->execute();

    return (int) $db->lastInsertRowID();
}

function renewal_tz_next_payment(SQLite3 $db, $id)
{
    return $db->querySingle('SELECT next_payment FROM subscriptions WHERE id = ' . (int) $id);
}

/**
 * Runs $body with the container time zone set to $timezone.
 */
function renewal_tz_in_container_zone($timezone, callable $body)
{
    $previous = date_default_timezone_get();
    date_default_timezone_set($timezone);
    try {
        return $body();
    } finally {
        date_default_timezone_set($previous);
    }
}

wallos_test('the account local date follows its notification time zone', function () {
    renewal_tz_in_container_zone('UTC', function () {
        $now = new DateTimeImmutable('2027-01-11 01:00:00', new DateTimeZone('UTC'));
        assert_same('2027-01-10', wallos_account_local_date(['timezone' => 'America/Los_Angeles'], $now), 'Los Angeles is still on the 10th');
        assert_same('2027-01-11', wallos_account_local_date(['timezone' => 'Asia/Tokyo'], $now), 'Tokyo is on the 11th');
        assert_same('2027-01-11', wallos_account_local_date([], $now), 'no time zone: the container date');
        assert_same('2027-01-11', wallos_account_local_date(['timezone' => 'Not/AZone'], $now), 'an invalid zone: the container date');
    });
});

wallos_test('the renewal cron waits for the end of the due day in the account time zone', function () {
    renewal_tz_in_container_zone('UTC', function () {
        $db = renewal_tz_database();
        $utc = renewal_tz_subscription($db, 1, 'utc', '2027-01-10');
        $la = renewal_tz_subscription($db, 2, 'la', '2027-01-10');
        $tokyo = renewal_tz_subscription($db, 3, 'tokyo', '2027-01-10');

        // 01:00 UTC on the 11th: 17:00 on the 10th in Los Angeles.
        $run = wallos_run_renewals($db, new DateTimeImmutable('2027-01-11 01:00:00', new DateTimeZone('UTC')));
        assert_same(2, $run['advanced'], 'two subscriptions advanced');
        assert_same('2027-02-10', renewal_tz_next_payment($db, $utc), 'the account without a time zone advances on the container date, as before');
        assert_same('2027-02-10', renewal_tz_next_payment($db, $tokyo), 'Tokyo, already on the 11th, advances');
        assert_same('2027-01-10', renewal_tz_next_payment($db, $la), 'Los Angeles, still on the 10th, keeps its payment');

        // The day-of reminder at 23:30 local still sees it.
        $settings = wallos_notification_settings_row($db, 2);
        $localNow = new DateTime('2027-01-10 23:30:00', new DateTimeZone('America/Los_Angeles'));
        assert_true(wallos_notification_slot_due($settings['send_time'], null, $localNow), 'the 23:30 slot is due');
        assert_same(0, wallos_days_between($localNow->format('Y-m-d'), renewal_tz_next_payment($db, $la)), 'the payment is due today on the account calendar');

        // 07:45 UTC on the 11th: 23:45 on the 10th, still not past.
        wallos_run_renewals($db, new DateTimeImmutable('2027-01-11 07:45:00', new DateTimeZone('UTC')));
        assert_same('2027-01-10', renewal_tz_next_payment($db, $la), 'not before local midnight');

        // 08:00 UTC: midnight on the 11th in Los Angeles.
        wallos_run_renewals($db, new DateTimeImmutable('2027-01-11 08:00:00', new DateTimeZone('UTC')));
        assert_same('2027-02-10', renewal_tz_next_payment($db, $la), 'advanced once the local due day has passed');
        assert_same(1, (int) $db->querySingle('SELECT COUNT(*) FROM subscription_payments WHERE subscription_id = ' . $la), 'one ledger row for the passed cycle');
        assert_same('2027-02-10', renewal_tz_next_payment($db, $utc), 'the others are not advanced twice');
    });
});

wallos_test('without account time zones the renewal cron uses the container calendar', function () {
    renewal_tz_in_container_zone('America/New_York', function () {
        $db = wallos_test_open_database();
        wallos_test_create_user($db, 1, 'admin');
        $due = renewal_tz_subscription($db, 1, 'due', '2027-01-10');
        $today = renewal_tz_subscription($db, 1, 'today', '2027-01-11');
        $paused = renewal_tz_subscription($db, 1, 'manual', '2027-01-01', ['auto_renew' => 0]);

        // 02:00 UTC on the 12th is 21:00 on the 11th in New York.
        wallos_run_renewals($db, new DateTimeImmutable('2027-01-12 02:00:00', new DateTimeZone('UTC')));
        assert_same('2027-02-10', renewal_tz_next_payment($db, $due), 'a payment before the container date advances');
        assert_same('2027-01-11', renewal_tz_next_payment($db, $today), 'one due on the container date stays');
        assert_same('2027-01-01', renewal_tz_next_payment($db, $paused), 'one that does not auto-renew stays');
    });
});

wallos_test('intro periods end on the account calendar', function () {
    renewal_tz_in_container_zone('UTC', function () {
        $db = renewal_tz_database();
        $extra = ['intro_type' => 'promo', 'intro_price' => 1.0, 'intro_end_date' => '2027-01-11'];
        $utc = renewal_tz_subscription($db, 1, 'utc', '2027-03-01', $extra);
        $la = renewal_tz_subscription($db, 2, 'la', '2027-03-01', $extra);

        $run = wallos_run_renewals($db, new DateTimeImmutable('2027-01-11 01:00:00', new DateTimeZone('UTC')));
        assert_same(1, $run['converted'], 'only the account already on the 11th converts');
        assert_same('2027-01-11', $db->querySingle('SELECT intro_converted_on FROM subscriptions WHERE id = ' . $utc), 'converted on its local date');
        assert_same(null, $db->querySingle('SELECT intro_converted_on FROM subscriptions WHERE id = ' . $la), 'Los Angeles is still on the 10th');

        wallos_run_renewals($db, new DateTimeImmutable('2027-01-11 08:00:00', new DateTimeZone('UTC')));
        assert_same('2027-01-11', $db->querySingle('SELECT intro_converted_on FROM subscriptions WHERE id = ' . $la), 'converted after its local midnight');
    });
});

wallos_test('the renewal cron runs often enough to follow local midnights', function () {
    assert_contains('*/15 * * * * /usr/local/bin/php /var/www/html/endpoints/cronjobs/updatenextpayment.php', file_get_contents(WALLOS_ROOT . '/cronjobs'),
        'the container runs the renewal cron every 15 minutes');
    assert_contains('wallos_run_renewals(', file_get_contents(WALLOS_ROOT . '/endpoints/cronjobs/updatenextpayment.php'),
        'the cron script uses the per-account renewal pass');
});
