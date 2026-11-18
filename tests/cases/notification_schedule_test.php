<?php
/*
  When reminders go out (issues #39, #40) and the Apprise channel (#38).
*/

require_once WALLOS_ROOT . '/tests/notification_fixtures.php';
require_once WALLOS_ROOT . '/includes/notifications/apprise_settings.php';

function schedule_test_collect($db, $settings, $today)
{
    [$entries, $pending] = wallos_collect_payment_reminders($db, 1, $settings, $today, wallos_notification_lookups($db, 1));
    $names = [];
    foreach ($entries as $list) {
        foreach ($list as $entry) {
            $names[] = $entry['name'];
        }
    }

    return [$names, $pending];
}

wallos_test('reminder offsets are parsed, sorted and capped', function () {
    assert_same([7, 3, 0], wallos_parse_reminder_days('0, 3,7,3'), 'a list is deduplicated and sorted, largest first');
    assert_same([5], wallos_parse_reminder_days(['5', 'x', -1, 1000, 2.5]), 'invalid entries are dropped');
    assert_same([], wallos_parse_reminder_days(null), 'nothing is no reminder');
    assert_same(WALLOS_REMINDER_MAX_OFFSETS, count(wallos_parse_reminder_days(range(0, 40))), 'at most ten offsets');
    assert_same('7,3,0', wallos_format_reminder_days([0, 7, 3]), 'stored as a comma list');

    assert_same([3], wallos_default_reminder_days(['days' => 3]), 'the account default falls back to the single legacy value');
    assert_same([7, 1], wallos_default_reminder_days(['days' => 7, 'reminder_days' => '1,7']), 'or uses its own list');
    assert_same([14, 2], wallos_subscription_reminder_days(['reminder_days' => '2,14', 'notify_days_before' => 14], [1]), 'a subscription list wins');
    assert_same([4], wallos_subscription_reminder_days(['reminder_days' => null, 'notify_days_before' => 4], [1]), 'then its single value');
    assert_same([1], wallos_subscription_reminder_days(['reminder_days' => null, 'notify_days_before' => -1], [1]), 'then the account default');
});

wallos_test('several reminders go out once each, and a missed one is caught up once', function () {
    $offsets = [7, 3, 0];

    assert_same([7], wallos_due_reminder_offsets(7, $offsets, [], true, []), 'the 7-day reminder opens its window');
    assert_same([], wallos_due_reminder_offsets(6, $offsets, [], true, [7]), 'nothing the day after');
    assert_same([3], wallos_due_reminder_offsets(3, $offsets, [], true, [7]), 'the 3-day reminder');
    assert_same([0], wallos_due_reminder_offsets(0, $offsets, [], true, [7, 3]), 'the one on the day');
    assert_same([7], wallos_due_reminder_offsets(5, $offsets, [], true, []), 'a missed 7-day run is caught up two days later');
    assert_same([7, 3], wallos_due_reminder_offsets(2, $offsets, [], true, []), 'two missed windows make one message logging both');
    assert_same([], wallos_due_reminder_offsets(-1, $offsets, [], true, []), 'never for a payment already past');

    assert_same([7, 3], wallos_logged_offsets([], '2026-12-10', '2026-12-10', 2, $offsets),
        'the legacy single marker counts as every window that had opened');
    assert_same([], wallos_logged_offsets([], '2026-11-10', '2026-12-10', 2, $offsets), 'a marker for another payment counts for nothing');
    assert_same([7], wallos_logged_offsets([7], '2026-12-10', '2026-12-10', 2, $offsets), 'once the log has rows it is the record');
});

wallos_test('an unpaid subscription that does not renew is reminded again, at most N times', function () {
    $overdue = wallos_overdue_offsets(3, 2);
    assert_same([-3, -6], $overdue, 'every 3 days, twice');
    assert_same([], wallos_overdue_offsets(0, 5), 'off without an interval');

    assert_same([], wallos_due_reminder_offsets(-2, [1], $overdue, false, [1]), 'not before the first interval');
    assert_same([-3], wallos_due_reminder_offsets(-3, [1], $overdue, false, [1]), 'three days overdue');
    assert_same([], wallos_due_reminder_offsets(-4, [1], $overdue, false, [1, -3]), 'not again the next day');
    assert_same([-6], wallos_due_reminder_offsets(-7, [1], $overdue, false, [1, -3]), 'the second repeat, a day late');
    assert_same([], wallos_due_reminder_offsets(-30, [1], $overdue, false, [1, -3, -6]), 'and no more');
    assert_same([], wallos_due_reminder_offsets(-3, [1], $overdue, true, [1]), 'an auto-renewing subscription is never overdue');
});

wallos_test('each account runs once a day at its own send time and time zone', function () {
    $at = fn($time, $zone = 'UTC') => new DateTime('2026-11-18 ' . $time, new DateTimeZone($zone));

    assert_true(!wallos_notification_slot_due('09:00', null, $at('08:45')), 'not before the send time');
    assert_true(wallos_notification_slot_due('09:00', null, $at('09:00')), 'at the send time');
    assert_true(wallos_notification_slot_due('09:00', '2026-11-17', $at('13:30')), 'a run missed earlier in the day catches up');
    assert_true(!wallos_notification_slot_due('09:00', '2026-11-18', $at('09:15')), 'once a day');
    assert_true(wallos_notification_slot_due('nonsense', null, $at('09:00')), 'an invalid time falls back to 09:00');

    assert_same('07:45', wallos_normalize_send_time('7:45'), 'a time is normalized');
    assert_same(null, wallos_normalize_send_time('07:40'), 'only 15-minute steps');
    assert_same(null, wallos_normalize_send_time('24:00'), 'a real time of day');

    assert_true(wallos_valid_timezone('Europe/Lisbon'), 'a known zone');
    assert_true(!wallos_valid_timezone('Mars/Olympus'), 'an unknown one');
    assert_same('Asia/Tokyo', wallos_notification_timezone(['timezone' => 'Asia/Tokyo'])->getName(), 'the account zone');
    assert_same(date_default_timezone_get(), wallos_notification_timezone(['timezone' => ''])->getName(), 'or the server\'s');

    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    $db->exec('DELETE FROM notification_settings WHERE user_id = 1');
    wallos_mark_notification_run($db, 1, 'last_payment_run', '2026-11-18');
    assert_same('2026-11-18', $db->querySingle('SELECT last_payment_run FROM notification_settings WHERE user_id = 1'), 'a run is recorded, row created');
    wallos_mark_notification_run($db, 1, 'last_cancellation_run', '2026-11-19');
    assert_same(1, $db->querySingle('SELECT COUNT(*) FROM notification_settings WHERE user_id = 1'), 'on the same row');

    $cron = file_get_contents(WALLOS_ROOT . '/cronjobs');
    assert_contains('*/15 * * * * /usr/local/bin/php /var/www/html/endpoints/cronjobs/sendnotifications.php', $cron, 'payment reminders run every 15 minutes');
    assert_contains('*/15 * * * * /usr/local/bin/php /var/www/html/endpoints/cronjobs/sendcancellationnotifications.php', $cron, 'and cancellation reminders');
    $db->close();
});

wallos_test('the payment collector sends each reminder once across runs', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    notification_test_household($db, 1, 1, 'Alice');
    $today = '2026-11-18';
    $id = notification_test_subscription($db, 1, ['name' => 'Multi', 'next_payment' => '2026-11-25', 'reminder_days' => '7,3,0', 'notify_days_before' => 7]);
    notification_test_subscription($db, 1, ['name' => 'Off', 'next_payment' => '2026-11-19', 'notify' => 0]);
    notification_test_subscription($db, 1, ['name' => 'Gone', 'next_payment' => '2026-11-19', 'inactive' => 1]);

    [$names, $pending] = schedule_test_collect($db, ['days' => 1], $today);
    assert_same(['Multi'], $names, 'only the enabled subscription inside a window');
    assert_same([7], $pending['payment:' . $id]['offsets'], 'the 7-day reminder');

    wallos_record_payment_reminders($db, 1, $pending, ['payment:' . $id => true]);
    [$names] = schedule_test_collect($db, ['days' => 1], $today);
    assert_same([], $names, 'a second run the same day sends nothing');
    [$names] = schedule_test_collect($db, ['days' => 1], '2026-11-20');
    assert_same([], $names, 'nor two days later');
    [$names, $pending] = schedule_test_collect($db, ['days' => 1], '2026-11-22');
    assert_same(['Multi'], $names, 'the 3-day reminder');
    wallos_record_payment_reminders($db, 1, $pending, ['payment:' . $id => true]);
    [$names] = schedule_test_collect($db, ['days' => 1], '2026-11-25');
    assert_same(['Multi'], $names, 'and the one on the day');

    assert_same(2, $db->querySingle('SELECT COUNT(*) FROM notification_log WHERE subscription_id = ' . $id), 'two log rows so far');
    wallos_notification_log_record($db, 1, 'payment', [['subscription_id' => $id, 'due_date' => '2026-11-25', 'offset' => 7]]);
    assert_same(2, $db->querySingle('SELECT COUNT(*) FROM notification_log WHERE subscription_id = ' . $id), 'a duplicate log row is ignored');
    $db->close();
});

wallos_test('overdue repeats and the mute switch reach the collector', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    notification_test_household($db, 1, 1, 'Alice');
    $today = '2026-11-18';
    notification_test_subscription($db, 1, ['name' => 'Unpaid', 'next_payment' => '2026-11-12', 'auto_renew' => 0]);
    notification_test_subscription($db, 1, ['name' => 'Renews', 'next_payment' => '2026-11-12', 'auto_renew' => 1]);

    [$names] = schedule_test_collect($db, ['days' => 1], $today);
    assert_same([], $names, 'no overdue repeats unless the account turns them on');
    [$names, $pending] = schedule_test_collect($db, ['days' => 1, 'repeat_every' => 3, 'repeat_max' => 3], $today);
    assert_same(['Unpaid'], $names, 'a non-renewing payment six days overdue is reminded');
    assert_same([-3, -6], array_values($pending)[0]['offsets'], 'logging both repeats it covers');

    // A contract: no payment reminders until the cancel-by date.
    $db->exec('DELETE FROM subscriptions');
    notification_test_subscription($db, 1, ['name' => 'Contract', 'next_payment' => '2026-11-19', 'mute_payment_until_cancellation' => 1,
        'cancel_notice_mode' => 'fixed', 'cancellation_date' => '2027-06-01']);
    notification_test_subscription($db, 1, ['name' => 'After', 'next_payment' => '2027-06-02', 'mute_payment_until_cancellation' => 1,
        'cancel_notice_mode' => 'fixed', 'cancellation_date' => '2027-06-01']);
    [$names] = schedule_test_collect($db, ['days' => 1], $today);
    assert_same([], $names, 'a payment before the deadline is muted');
    [$names] = schedule_test_collect($db, ['days' => 1], '2027-06-01');
    assert_same(['After'], $names, 'payments after the deadline are reminded again');
    assert_true(!wallos_payment_reminder_muted(['mute_payment_until_cancellation' => 1, 'next_payment' => '2026-11-19'], null),
        'no deadline, nothing muted');
    $db->close();
});

wallos_test('a cancel-by date can be reminded ahead, and again on the day', function () {
    $row = ['cycle' => 3, 'inactive' => 0, 'cancel_notice_mode' => 'fixed', 'cancellation_date' => '2026-12-01',
        'next_payment' => '2026-12-15', 'cancellation_notify_days_before' => 7, 'last_cancel_notified_for' => null];

    assert_same(null, wallos_cancellation_due($row, '2026-11-23'), 'nothing before the lead window');
    $lead = wallos_cancellation_due($row, '2026-11-24');
    assert_same(['lead', 'lead:2026-12-01'], [$lead['stage'], $lead['marker']], 'the early reminder');
    $row['last_cancel_notified_for'] = $lead['marker'];
    assert_same(null, wallos_cancellation_due($row, '2026-11-28'), 'once');
    $final = wallos_cancellation_due($row, '2026-12-01');
    assert_same(['final', '2026-12-01'], [$final['stage'], $final['marker']], 'and the one on the date');
    $row['last_cancel_notified_for'] = $final['marker'];
    assert_same(null, wallos_cancellation_due($row, '2026-12-02'), 'then nothing');

    $row['cancellation_notify_days_before'] = 0;
    $row['last_cancel_notified_for'] = null;
    assert_same(null, wallos_cancellation_due($row, '2026-11-24'), 'without a lead time, only the date itself');
    assert_same('final', wallos_cancellation_due($row, '2026-12-01')['stage'], 'as before');

    $row['cancellation_notify_days_before'] = 7;
    assert_same('final', wallos_cancellation_due($row, '2026-12-03')['stage'], 'a missed lead window goes straight to the reminder on the date');

    // Through the collector: the early reminder says when to cancel by.
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    notification_test_household($db, 1, 1, 'Alice');
    $id = notification_test_subscription($db, 1, ['name' => 'Contract', 'next_payment' => '2026-12-15',
        'cancel_notice_mode' => 'fixed', 'cancellation_date' => '2026-12-01', 'cancellation_notify_days_before' => 30]);
    [$entries, $markers] = wallos_collect_cancellation_reminders($db, 1, '2026-11-18', wallos_notification_lookups($db, 1));
    assert_same(' (cancel by 2026-12-01)', $entries[1][0]['deadline_note'], 'the early reminder names the deadline');
    wallos_record_cancellation_reminders($db, 1, $markers, [wallos_notification_key('cancel', $id) => true]);
    assert_same('lead:2026-12-01', $db->querySingle('SELECT last_cancel_notified_for FROM subscriptions WHERE id = ' . $id), 'its marker is stored');
    [$entries, $markers] = wallos_collect_cancellation_reminders($db, 1, '2026-12-01', wallos_notification_lookups($db, 1));
    assert_same('', $entries[1][0]['deadline_note'], 'the reminder on the date reads as before');
    $db->close();
});

wallos_test('the dashboard flags a payment inside the reminder window as due soon', function () {
    assert_true(wallos_is_due_soon(3, [3, 0]), 'on the first day of the window');
    assert_true(wallos_is_due_soon(0, [3, 0]), 'on the day');
    assert_true(!wallos_is_due_soon(4, [3, 0]), 'not before');
    assert_true(wallos_is_due_soon(6, [7, 1]), 'the largest offset decides');
    assert_true(!wallos_is_due_soon(-1, [3]), 'overdue is not "due soon" (it has its own state)');
    assert_same(-2, wallos_days_between('2026-11-18', '2026-11-16'), 'days between, negative when past');

    $list = file_get_contents(WALLOS_ROOT . '/includes/list_subscriptions.php');
    assert_contains("'progress_tone' => \$progressTone", $list, 'the progress bar takes the due-soon or overdue tone');
});

wallos_test('the subscription form fields become stored reminder settings', function () {
    assert_same(['notify_days_before' => 7, 'reminder_days' => '7,3,0', 'cancellation_notify_days_before' => 14, 'mute_payment_until_cancellation' => 1],
        wallos_reminder_fields_from_input(['reminder_days' => ['0', '3', '7'], 'notify_days_before' => '-1', 'cancellation_notify_days_before' => '14', 'mute_payment_until_cancellation' => '1']),
        'chips set the list and the largest single value');
    assert_same(['notify_days_before' => -1, 'reminder_days' => null, 'cancellation_notify_days_before' => 0, 'mute_payment_until_cancellation' => 0],
        wallos_reminder_fields_from_input(['notify_days_before' => '-1']), 'the default');
    assert_same(5, wallos_reminder_fields_from_input(['notify_days_before' => '5'])['notify_days_before'], 'an old client\'s single value');
    assert_same(-1, wallos_reminder_fields_from_input(['notify_days_before' => '9999'])['notify_days_before'], 'out of range falls back to the default');
    assert_same(365, wallos_reminder_fields_from_input(['cancellation_notify_days_before' => '5000'])['cancellation_notify_days_before'], 'the lead time is capped');

    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    $id = notification_test_subscription($db, 1, ['name' => 'X']);
    assert_true(wallos_store_reminder_fields($db, $id, 1, wallos_reminder_fields_from_input(['reminder_days' => '1,14'])), 'stored');
    assert_same('14,1', $db->querySingle('SELECT reminder_days FROM subscriptions WHERE id = ' . $id), 'as a sorted list');
    assert_true(wallos_store_reminder_fields($db, $id, 2, wallos_reminder_fields_from_input([])) && $db->changes() === 0,
        'another account cannot change it');
    $db->close();
});

wallos_test('the notification migration is safe to run twice', function () {
    $db = wallos_test_open_database();
    require WALLOS_ROOT . '/migrations/000074.php';
    foreach (['notification_log', 'notification_templates', 'apprise_notifications'] as $table) {
        assert_same($table, $db->querySingle("SELECT name FROM sqlite_master WHERE type = 'table' AND name = '" . $table . "'"), $table . ' exists');
    }
    assert_same('09:00', $db->querySingle("SELECT dflt_value FROM pragma_table_info('notification_settings') WHERE name = 'send_time'") === "'09:00'" ? '09:00' : null,
        'send time defaults to 09:00');
    $db->close();
});

wallos_test('Apprise sends one digest to a saved key or to stateless URLs', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    $message = ['title' => 'Tally Notification', 'body' => "Line 1\nLine 2", 'custom_title' => false];
    $context = ['payer' => 'Ann', 'subscriptions' => [['name' => 'A', 'key' => 'payment:1'], ['name' => 'B', 'key' => 'payment:2']]];

    $requests = [];
    $apprise = new WallosAppriseChannel(['enabled' => 1, 'url' => 'https://apprise.example/', 'mode' => 'key', 'config_key' => 'tally',
        'urls' => '', 'tags' => 'family', 'ignore_ssl' => 1], notification_test_env($db, 1, $requests));
    assert_same(['payment:1', 'payment:2'], $apprise->sendForPayer($message, $context), 'one request delivers the payer\'s reminders');
    assert_same(1, count($requests), 'a single digest');
    assert_same('https://apprise.example/notify/tally', $requests[0]['url'], 'to the stateful key');
    assert_same(['title' => 'Tally Notification', 'body' => "Line 1\nLine 2", 'type' => 'info', 'format' => 'text', 'tag' => 'family'],
        json_decode($requests[0]['body'], true), 'title, body, type, format and tags');
    assert_true($requests[0]['ignore_ssl'] && $requests[0]['ssrf']['ip'] === '203.0.113.10', 'pinned to the checked address, SSL setting kept');

    $requests = [];
    $log = [];
    $stateless = new WallosAppriseChannel(['enabled' => 1, 'url' => 'https://apprise.example', 'mode' => 'urls',
        'urls' => "tgram://secret-token/chat\n\n mailto://user:pass@example.com ", 'tags' => '', 'ignore_ssl' => 0],
        notification_test_env($db, 1, $requests, ['ok' => false, 'code' => 500, 'body' => 'tgram://secret-token/chat failed', 'error' => ''], $log));
    assert_same([], $stateless->sendForPayer($message, $context), 'a failed request delivers nothing');
    assert_same('https://apprise.example/notify/', $requests[0]['url'], 'stateless requests go to /notify/');
    assert_same('tgram://secret-token/chat,mailto://user:pass@example.com', json_decode($requests[0]['body'], true)['urls'], 'with the destination URLs');
    assert_true(!preg_grep('/secret-token/', $log), 'the secret-bearing URLs never reach the log');

    $requests = [];
    $blocked = new WallosAppriseChannel(['enabled' => 1, 'url' => 'https://blocked.example', 'mode' => 'key', 'config_key' => 'k'], notification_test_env($db, 1, $requests));
    assert_same([], $blocked->sendForPayer($message, $context), 'a server that fails the SSRF check is not called');
    assert_same([], $requests, 'nothing was sent');

    assert_same(null, WallosAppriseChannel::buildRequest(['url' => 'https://a.example', 'mode' => 'key', 'config_key' => '../x'], 't', 'b'),
        'a key that is not a plain token is refused');
    assert_same(null, WallosAppriseChannel::buildRequest(['url' => 'https://a.example', 'mode' => 'urls', 'urls' => " \n"], 't', 'b'),
        'stateless mode needs a URL');
    $db->close();
});

wallos_test('Apprise settings are validated before they are saved', function () {
    $ok = wallos_apprise_settings_from_input(['enabled' => 1, 'url' => 'http://apprise:8000', 'mode' => 'key', 'config_key' => 'tally']);
    assert_same(null, $ok['error'], 'a sidecar URL and key');
    assert_same('fill_mandatory_fields', wallos_apprise_settings_from_input(['enabled' => 1, 'url' => '', 'mode' => 'key', 'config_key' => 'k'])['error'], 'a server URL is required');
    assert_same('error', wallos_apprise_settings_from_input(['enabled' => 1, 'url' => 'ftp://x', 'mode' => 'key', 'config_key' => 'k'])['error'], 'only http(s)');
    assert_same('apprise_invalid_key', wallos_apprise_settings_from_input(['enabled' => 1, 'url' => 'https://x.example', 'mode' => 'key', 'config_key' => 'a/b'])['error'], 'a plain key');
    assert_same('fill_mandatory_fields', wallos_apprise_settings_from_input(['enabled' => 1, 'url' => 'https://x.example', 'mode' => 'urls'])['error'], 'stateless needs URLs');
    assert_same(null, wallos_apprise_settings_from_input(['enabled' => 0])['error'], 'disabled settings can be saved incomplete');
    assert_same('fill_mandatory_fields', wallos_apprise_settings_from_input(['enabled' => 0], true)['error'], 'but not tested');

    $save = file_get_contents(WALLOS_ROOT . '/endpoints/notifications/saveapprisenotifications.php');
    assert_contains('validate_webhook_url_for_ssrf(', $save, 'the server URL passes the SSRF check when saved');
});
