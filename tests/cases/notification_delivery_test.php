<?php
/*
  Notification delivery from the cron jobs:

  - a renewal reminder is still sent when the cron missed the first day of the
    reminder window, but only once per payment date;
  - webhook payloads stay valid JSON whatever the subscription data contains,
    and are sent as application/json;
  - one failing channel does not stop the rest.

  The decision and payload helpers are tested directly, and the channels
  through the shared dispatcher with a fake HTTP transport
  (tests/notification_fixtures.php).
*/

require_once WALLOS_ROOT . '/includes/renewal_reminders.php';
require_once WALLOS_ROOT . '/includes/webhook_helper.php';
require_once WALLOS_ROOT . '/tests/notification_fixtures.php';

wallos_test('a renewal reminder is due on any day inside the window, once per payment', function () {
    $today = '2026-10-13';

    assert_true(isRenewalReminderDue(3, 3, '2026-10-16', $today, null),
        'due on the first day of the window');
    assert_true(isRenewalReminderDue(1, 3, '2026-10-14', $today, null),
        'still due two days later when the cron missed the first day');
    assert_true(isRenewalReminderDue(0, 0, '2026-10-13', $today, null),
        'a zero-day lead time is due on the payment day');
    assert_true(!isRenewalReminderDue(4, 3, '2026-10-17', $today, null),
        'not due before the window opens');
    assert_true(!isRenewalReminderDue(1, 3, '2026-10-14', $today, '2026-10-14'),
        'not due again once this payment was reminded');
    assert_true(isRenewalReminderDue(3, 3, '2026-11-16', $today, '2026-10-16'),
        'due again for the next payment date');
    assert_true(!isRenewalReminderDue(2, 3, '2026-10-11', $today, null),
        'never due for a payment date already in the past');
    assert_true(!isRenewalReminderDue(1, null, '2026-10-14', $today, null),
        'a subscription without a lead time is never reminded');
});

wallos_test('the cron collects due reminders and records only what was delivered', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    notification_test_household($db, 1, 1, 'Alice');
    $today = date('Y-m-d');

    $due = notification_test_subscription($db, 1, ['name' => 'Due', 'next_payment' => notification_test_date(1), 'notify_days_before' => 3]);
    $other = notification_test_subscription($db, 1, ['name' => 'Other', 'next_payment' => notification_test_date(2), 'notify_days_before' => 3]);
    notification_test_subscription($db, 1, ['name' => 'Later', 'next_payment' => notification_test_date(10), 'notify_days_before' => 3]);

    [$entries, $pending] = wallos_collect_payment_reminders($db, 1, ['days' => 1], $today, wallos_notification_lookups($db, 1));
    assert_same(['Due', 'Other'], array_column($entries[1] ?? [], 'name'), 'the two payments inside their window are due');

    // Only "Due" was delivered.
    wallos_record_payment_reminders($db, 1, $pending, ['payment:' . $due => true]);
    assert_same(notification_test_date(1), $db->querySingle('SELECT last_notified_payment FROM subscriptions WHERE id = ' . $due),
        'the delivered reminder stores its payment date');
    assert_same(null, $db->querySingle('SELECT last_notified_payment FROM subscriptions WHERE id = ' . $other),
        'the undelivered one does not');

    [$entries] = wallos_collect_payment_reminders($db, 1, ['days' => 1], $today, wallos_notification_lookups($db, 1));
    assert_same(['Other'], array_column($entries[1] ?? [], 'name'), 'the next run tries only the undelivered one again');

    $source = file_get_contents(WALLOS_ROOT . '/endpoints/cronjobs/sendnotifications.php');
    assert_contains('wallos_collect_payment_reminders(', $source, 'the cron collects through the shared helper');
    assert_contains('wallos_record_payment_reminders(', $source, 'and records through it');
    $db->close();
});

wallos_test('the reminder migration marks only subscriptions already past their first reminder day', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');

    $db->exec('ALTER TABLE subscriptions DROP COLUMN last_notified_payment');
    $db->exec('DELETE FROM notification_settings WHERE user_id = 1');
    $db->exec('INSERT INTO notification_settings (user_id, days) VALUES (1, 3)');

    $rows = [
        // name,        days until payment, notify_days_before
        ['Inside', 1, -1],     // past the first day of a 3-day window -> marked
        ['FirstDay', 3, -1],   // window opens today                   -> left alone
        ['Later', 10, -1],     // window not open yet                  -> left alone
        ['Own', 5, 7],         // own 7-day window, opened 2 days ago  -> marked
    ];

    $stmt = $db->prepare('INSERT INTO subscriptions (user_id, name, price, currency_id, next_payment, cycle, frequency, inactive, notify, notify_days_before)
                          VALUES (1, :name, 9.99, :currencyId, :nextPayment, 3, 1, 0, 1, :daysBefore)');
    foreach ($rows as [$name, $daysUntil, $daysBefore]) {
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->bindValue(':currencyId', wallos_test_currency_id(1, 0), SQLITE3_INTEGER);
        $stmt->bindValue(':nextPayment', (new DateTime('today'))->modify('+' . $daysUntil . ' days')->format('Y-m-d'), SQLITE3_TEXT);
        $stmt->bindValue(':daysBefore', $daysBefore, SQLITE3_INTEGER);
        $stmt->execute();
        $stmt->reset();
    }

    require WALLOS_ROOT . '/migrations/000061.php';

    $marked = [];
    $result = $db->query('SELECT name FROM subscriptions WHERE user_id = 1 AND last_notified_payment = next_payment ORDER BY name');
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $marked[] = $row['name'];
    }

    assert_same(['Inside', 'Own'], $marked, 'only subscriptions already inside their window are marked');

    require WALLOS_ROOT . '/migrations/000061.php';
    assert_true(true, 'the migration can run twice');

    $db->close();
});

wallos_test('every webhook placeholder is JSON-escaped', function () {
    $template = '{"name": "{{subscription_name}}", "category": "{{subscription_category}}", "url": "{{subscription_url}}", "days": {{days_until}}, "notes": "{{subscription_notes}}"}';
    $values = [
        'subscription_name' => 'Say "hi"',
        'subscription_category' => 'C:\\Temp',
        'subscription_url' => 'https://example.com/?a="b"',
        'days_until' => 3,
        'subscription_notes' => "line one\nline two",
    ];

    $payload = webhookFillPayload($template, $values);
    $decoded = json_decode($payload, true);

    assert_true(is_array($decoded), 'the payload is valid JSON: ' . $payload);
    assert_same('Say "hi"', $decoded['name'] ?? null, 'a quoted name round-trips');
    assert_same('C:\\Temp', $decoded['category'] ?? null, 'a backslash round-trips');
    assert_same('https://example.com/?a="b"', $decoded['url'] ?? null, 'a URL with quotes round-trips');
    assert_same(3, $decoded['days'] ?? null, 'a numeric placeholder stays numeric');
    assert_same("line one\nline two", $decoded['notes'] ?? null, 'a multi-line note round-trips');

    assert_same('{"v": "{{subscription_name}}"}', webhookFillPayload('{"v": "{{subscription_name}}"}', ['subscription_name' => '{{subscription_name}}']),
        'a value is not templated a second time');
});

wallos_test('webhook requests are sent as JSON unless the user chose a content type', function () {
    assert_same(['Content-Type: application/json'], webhookBuildHeaders(''),
        'no custom headers gets a JSON content type');
    assert_same(['Authorization: Bearer x', 'Content-Type: application/json'], webhookBuildHeaders('["Authorization: Bearer x"]'),
        'a JSON list of headers is kept and the content type added');
    assert_same(['Authorization: Bearer x', 'Content-Type: application/json'], webhookBuildHeaders('{"Authorization": "Bearer x"}'),
        'a JSON object of headers becomes Name: value lines');
    assert_same(['X-One: 1', 'X-Two: 2', 'Content-Type: application/json'], webhookBuildHeaders("X-One: 1\r\nX-Two: 2\n"),
        'one header per line is accepted too');
    assert_same(['content-type: text/plain'], webhookBuildHeaders('["content-type: text/plain"]'),
        'a content type the user set is left alone');
});

wallos_test('the webhook channel and the test endpoint share the webhook helpers', function () {
    foreach ([
        'includes/notifications/channels/webhook.php',
        'endpoints/notifications/testwebhooknotifications.php',
    ] as $file) {
        $source = file_get_contents(WALLOS_ROOT . '/' . $file);

        assert_contains('webhookFillPayload(', $source, $file . ' fills the payload with webhookFillPayload()');
        assert_contains('webhookBuildHeaders(', $source, $file . ' builds headers with webhookBuildHeaders()');
    }
});

wallos_test('a channel that throws does not stop the channels after it', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    $requests = [];
    $log = [];
    $env = notification_test_env($db, 1, $requests, null, $log);

    $failing = new class(['enabled' => 1], $env) extends WallosNotificationChannel {
        const NAME = 'discord';
        const LABEL = 'Broken';
        protected function send(array $message, array $context)
        {
            throw new RuntimeException('boom');
        }
    };
    $working = new WallosGotifyChannel(['enabled' => 1, 'url' => 'https://gotify.example', 'token' => 't'], $env);

    $context = ['payer' => 'Ann', 'subscriptions' => [['name' => 'X', 'price' => '1€', 'deadline_note' => '', 'key' => 'cancel:1']]];
    $delivered = wallos_dispatch_notifications($env, [$failing, $working], 'cancellation', [$context], []);

    assert_same(['cancel:1' => true], $delivered, 'the channel after the failing one still delivered');
    assert_same(1, count($requests), 'one request was sent');
    assert_true((bool) preg_grep('/Error sending Broken notifications: boom/', $log), 'the failure is logged');
    $db->close();
});

wallos_test('channels handle provider responses and settings correctly', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    $message = ['title' => 'T', 'body' => 'Body', 'custom_title' => false];
    $context = ['kind' => 'payment', 'payer' => 'Ann', 'subscriptions' => [
        ['name' => 'A', 'formatted_price' => '€ 1', 'days' => 3, 'key' => 'payment:1'],
        ['name' => 'B', 'formatted_price' => '€ 2', 'days' => 0, 'key' => 'payment:2'],
    ]];

    // ntfy with no custom headers does not throw.
    $requests = [];
    $ntfy = new WallosNtfyChannel(['enabled' => 1, 'host' => 'https://ntfy.example/', 'topic' => 'subs', 'headers' => '', 'ignore_ssl' => 0], notification_test_env($db, 1, $requests));
    assert_same(['payment:1', 'payment:2'], $ntfy->sendForPayer($message, $context), 'ntfy delivers with empty headers');
    assert_same('https://ntfy.example/subs', $requests[0]['url'], 'to the topic URL');

    // {{days_until}} is each subscription's own days left.
    $requests = [];
    $webhook = new WallosWebhookChannel(['enabled' => 1, 'url' => 'https://hook.example', 'request_method' => 'POST', 'headers' => '',
        'payload' => '{"days":{{days_until}},"name":"{{subscription_name}}"}', 'ignore_ssl' => 0], notification_test_env($db, 1, $requests));
    $webhook->sendForPayer($message, $context);
    assert_same([['days' => 3, 'name' => 'A'], ['days' => 0, 'name' => 'B']], array_map(fn($r) => json_decode($r['body'], true), $requests),
        'one request per subscription, each with its own days left');

    // A Mattermost failure reports its HTTP status and delivers nothing.
    $requests = [];
    $log = [];
    $mattermost = new WallosMattermostChannel(['enabled' => 1, 'webhook_url' => 'https://mm.example/hooks/x', 'bot_username' => '', 'bot_icon_emoji' => ''],
        notification_test_env($db, 1, $requests, ['ok' => false, 'code' => 500, 'body' => 'oops', 'error' => 'HTTP 500'], $log));
    assert_same([], $mattermost->sendForPayer($message, $context), 'an HTTP error is not a delivery');
    assert_true((bool) preg_grep('/Error sending Mattermost notification: HTTP 500/', $log), 'the status is logged');

    // Telegram counts a 200 with "ok": false as undelivered.
    $requests = [];
    $telegram = new WallosTelegramChannel(['enabled' => 1, 'bot_token' => 'b', 'chat_id' => 'c'],
        notification_test_env($db, 1, $requests, ['ok' => true, 'code' => 200, 'body' => '{"ok":false}', 'error' => '']));
    assert_same([], $telegram->sendForPayer($message, $context), 'Telegram reports failures in its body');

    // PushPlus likewise reads its "code".
    $requests = [];
    $pushplus = new WallosPushPlusChannel(['enabled' => 1, 'token' => 'p'],
        notification_test_env($db, 1, $requests, ['ok' => true, 'code' => 200, 'body' => '{"code":500,"msg":"bad token"}', 'error' => '']));
    assert_same([], $pushplus->sendForPayer($message, $context), 'a PushPlus error code is not a delivery');
    assert_same('订阅续期提醒 - Tally', json_decode($requests[0]['body'], true)['title'], 'PushPlus keeps its own default payment title');

    // An SSRF refusal sends nothing.
    $requests = [];
    $discord = new WallosDiscordChannel(['enabled' => 1, 'webhook_url' => 'https://blocked.example/x'], notification_test_env($db, 1, $requests));
    assert_same([], $discord->sendForPayer($message, $context), 'a refused URL delivers nothing');
    assert_same([], $requests, 'and is never called');
    $db->close();
});
