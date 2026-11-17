<?php
/*
  The cancellation notification cron must never notify for one-time purchases:
  they have no recurring commitment to cancel, and the subscription form clears
  their cancellation date.

  The cron's query is inline (the file requires PHPMailer and the notification
  providers, so it cannot be included here), so the test reads the statement out
  of the file and runs that — asserting against the real SQL, not a copy of it.
*/

/**
 * Returns the subscription SELECT the cancellation cron runs.
 *
 * @return string
 */
function cancellation_cron_query()
{
    $source = file_get_contents(WALLOS_ROOT . '/endpoints/cronjobs/sendcancellationnotifications.php');

    if (!preg_match('/\$query = "(SELECT \* FROM subscriptions WHERE[^"]+)";/', $source, $matches)) {
        return '';
    }

    return $matches[1];
}

/**
 * Runs the cron's query for one user, then its due check, and returns the
 * subscription names it would notify.
 *
 * @param SQLite3 $db
 * @param int     $userId
 * @return array
 */
function cancellation_cron_names($db, $userId, $today = null)
{
    require_once WALLOS_ROOT . '/includes/cancellation_deadline.php';

    $stmt = $db->prepare(cancellation_cron_query());
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':inactive', 0, SQLITE3_INTEGER);
    $stmt->bindValue(':oneTimeCycle', 5, SQLITE3_INTEGER);

    $names = [];
    $result = $stmt->execute();
    while ($result && $row = $result->fetchArray(SQLITE3_ASSOC)) {
        if (wallos_cancellation_due($row, $today ?? date('Y-m-d')) !== null) {
            $names[] = $row['name'];
        }
    }
    sort($names);

    return $names;
}

wallos_test('the cancellation cron query was found in the cron', function () {
    assert_contains('cancellation_date', cancellation_cron_query(),
        'the cron still selects subscriptions by cancellation date');
});

wallos_test('the cancellation cron skips one-time purchases due today', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');

    $today = date('Y-m-d');
    $rows = [
        ['Netflix', 3, 0, $today],        // recurring, due today  -> notify
        ['Udemy Course', 5, 0, $today],   // one-time, due today   -> never notify
        ['PureGym', 3, 1, $today],        // inactive              -> no notify
        ['Spotify', 3, 0, date('Y-m-d', strtotime('+3 days'))], // not due yet
    ];

    $stmt = $db->prepare('INSERT INTO subscriptions (user_id, name, price, currency_id, next_payment, cycle, frequency, inactive, cancellation_date)
                          VALUES (1, :name, 9.99, :currencyId, :nextPayment, :cycle, 1, :inactive, :cancellationDate)');
    foreach ($rows as [$name, $cycle, $inactive, $cancellationDate]) {
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->bindValue(':currencyId', wallos_test_currency_id(1, 0), SQLITE3_INTEGER);
        $stmt->bindValue(':nextPayment', date('Y-m-d', strtotime('+5 days')), SQLITE3_TEXT);
        $stmt->bindValue(':cycle', $cycle, SQLITE3_INTEGER);
        $stmt->bindValue(':inactive', $inactive, SQLITE3_INTEGER);
        $stmt->bindValue(':cancellationDate', $cancellationDate, SQLITE3_TEXT);
        $stmt->execute();
    }

    assert_same(['Netflix'], cancellation_cron_names($db, 1),
        'only the recurring subscription due today is notified');

    $db->close();
});

wallos_test('the shared helper skips one-time purchases and the other exclusions', function () {
    require_once WALLOS_ROOT . '/includes/upcoming_cancellations.php';

    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');

    $rows = [
        // name,            cycle, inactive, cancellation_date
        ['Netflix',             3, 0, date('Y-m-d', strtotime('+3 days'))],  // shown
        ['Spotify',             3, 0, date('Y-m-d', strtotime('+12 days'))], // shown
        ['Udemy Course',        5, 0, date('Y-m-d', strtotime('+1 day'))],   // one-time
        ['PureGym',             3, 1, date('Y-m-d', strtotime('+4 days'))],  // inactive
        ['Audible',             3, 0, date('Y-m-d', strtotime('-2 days'))],  // already passed
        ['Duolingo',            3, 0, ''],                                   // empty string
        ['iCloud+',             3, 0, null],                                 // never set
    ];

    $stmt = $db->prepare('INSERT INTO subscriptions (user_id, name, price, currency_id, next_payment, cycle, frequency, inactive, cancellation_date)
                          VALUES (1, :name, 10.00, :currencyId, :nextPayment, :cycle, 1, :inactive, :cancellationDate)');
    foreach ($rows as [$name, $cycle, $inactive, $cancellationDate]) {
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->bindValue(':currencyId', wallos_test_currency_id(1, 0), SQLITE3_INTEGER);
        $stmt->bindValue(':nextPayment', date('Y-m-d', strtotime('+5 days')), SQLITE3_TEXT);
        $stmt->bindValue(':cycle', $cycle, SQLITE3_INTEGER);
        $stmt->bindValue(':inactive', $inactive, SQLITE3_INTEGER);
        $stmt->bindValue(':cancellationDate', $cancellationDate, $cancellationDate === null ? SQLITE3_NULL : SQLITE3_TEXT);
        $stmt->execute();
    }

    $names = array_map(fn($row) => $row['name'], get_upcoming_cancellations($db, 1));

    assert_same(['Netflix', 'Spotify'], $names,
        'only active, recurring subscriptions with a future cancellation date are listed, soonest first');

    $db->close();
});

wallos_test('the dashboard and the statistics page both read the shared helper', function () {
    foreach (['index.php', 'stats.php'] as $page) {
        $source = file_get_contents(WALLOS_ROOT . '/' . $page);

        assert_contains('get_upcoming_cancellations(', $source,
            $page . ' calls the shared helper');
        assert_not_contains('cancellation_date >= date', $source,
            $page . ' does not carry its own copy of the query');
    }
});

wallos_test('the potential-savings total normalizes each cycle and converts each currency', function () {
    require_once WALLOS_ROOT . '/includes/upcoming_cancellations.php';

    // stats_calculations.php supplies these in the app, but including it runs a
    // page's worth of queries. Stubbing them keeps this focused on the summing
    // logic: every row must be normalized to a month AND converted, then added.
    if (!function_exists('getPricePerMonth')) {
        function getPricePerMonth($cycle, $frequency, $price)
        {
            return $cycle == 4 ? $price / (12 * $frequency) : $price / $frequency;
        }
    }
    if (!function_exists('getPriceConverted')) {
        // a distinctive factor, so a row that skipped conversion is visible
        function getPriceConverted($price, $currency, $database, $userId)
        {
            return $price * 2;
        }
    }

    $rows = [
        ['price' => 10.00, 'cycle' => 3, 'frequency' => 1, 'currency_id' => 1], // 10/mo
        ['price' => 120.00, 'cycle' => 4, 'frequency' => 1, 'currency_id' => 2], // 10/mo
    ];

    assert_same(40.0, get_upcoming_cancellations_monthly_value($rows, null, 1),
        'both rows are normalized to a month, converted, and summed');

    assert_same(0, get_upcoming_cancellations_monthly_value([], null, 1),
        'nothing flagged means nothing to save');
});

wallos_test('the cancellation cron keeps the account id while looping over household members', function () {
    $source = file_get_contents(WALLOS_ROOT . '/endpoints/cronjobs/sendcancellationnotifications.php');

    // Reusing $userId as the loop key replaced the account id with a household
    // member id, so every channel after email ran its SSRF check (and its
    // household lookup) against the wrong account.
    assert_not_contains('foreach ($notify as $userId', $source,
        'no channel loop overwrites $userId');
    assert_not_contains("household WHERE id = :userId'", $source,
        'household lookups are not by id alone');
    assert_same(7, substr_count($source, "household WHERE id = :id AND user_id = :ownerId"),
        'every channel looks up the payer within the owning account');
});

wallos_test('only delivered reminders are marked as sent, per reminder and per type', function () {
    require_once WALLOS_ROOT . '/includes/cancellation_deadline.php';

    // A webhook batch for one payer: the request for subscription 1 succeeded,
    // the one for subscription 2 failed.
    $delivered = [];
    wallos_notifications_delivered($delivered, [['key' => wallos_notification_key('cancel', 1)]]);

    assert_same([1 => '2026-12-01'], wallos_delivered_markers([1 => '2026-12-01', 2 => '2026-12-03'], $delivered, 'cancel'),
        'the failed reminder in a partly delivered batch stays unmarked and is retried');
    assert_same([], wallos_delivered_markers([1 => '2026-12-04'], $delivered, 'intro'),
        'a delivered cancellation reminder does not mark the trial reminder of the same subscription');

    // A per-payer message (email, Telegram, ...) delivers every reminder in it.
    $delivered = [];
    wallos_notifications_delivered($delivered, [
        ['key' => wallos_notification_key('cancel', 3)],
        ['key' => wallos_notification_key('intro', 4)],
    ]);
    assert_same([3 => 'a'], wallos_delivered_markers([3 => 'a'], $delivered, 'cancel'), 'the cancellation reminder in it');
    assert_same([4 => 'converted:2026-12-04'], wallos_delivered_markers([4 => 'converted:2026-12-04'], $delivered, 'intro'), 'and the trial notice');

    assert_same([], wallos_delivered_markers([5 => 'b'], [], 'cancel'), 'nothing delivered, nothing marked');
});

wallos_test('the cancellation cron counts an HTTP error answer as undelivered', function () {
    $source = file_get_contents(WALLOS_ROOT . '/endpoints/cronjobs/sendcancellationnotifications.php');

    assert_same(5, substr_count($source, '$httpCode < 200 || $httpCode >= 300'),
        'Discord, Gotify, Pushover, ntfy and the webhook accept only a 2xx answer');
    assert_contains('wallos_notification_http_delivered($httpCode, $result, true)', $source,
        'Telegram also checks the "ok" field of its answer');
    assert_same(6, substr_count($source, 'wallos_notifications_delivered($delivered, $perUser)'),
        'every per-payer channel records the reminders it delivered');
    assert_contains('wallos_notifications_delivered($delivered, [$subscription])', $source,
        'the webhook records each reminder on its own');
    assert_not_contains('deliveredPayers', $source, 'no per-payer shortcut is left');
});

wallos_test('a Telegram answer counts as delivered only with "ok": true', function () {
    require_once WALLOS_ROOT . '/includes/cancellation_deadline.php';

    assert_true(wallos_notification_http_delivered(200, '{"ok":true,"result":{}}', true), 'accepted message');
    assert_true(!wallos_notification_http_delivered(200, '{"ok":false,"error_code":400,"description":"chat not found"}', true),
        'a failure reported in a 200 answer');
    assert_true(!wallos_notification_http_delivered(200, 'not json', true), 'an unreadable answer');
    assert_true(!wallos_notification_http_delivered(403, '{"ok":false}', true), 'a rejected request');
    assert_true(!wallos_notification_http_delivered(0, false, true), 'no answer at all');
    assert_true(wallos_notification_http_delivered(204, '', false), 'other services: any 2xx');
    assert_true(!wallos_notification_http_delivered(500, 'error', false), 'other services: a 5xx');
});
