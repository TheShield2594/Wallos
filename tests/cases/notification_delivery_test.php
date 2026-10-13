<?php
/*
  Notification delivery from the cron jobs:

  - a renewal reminder is still sent when the cron missed the first day of the
    reminder window, but only once per payment date;
  - webhook payloads stay valid JSON whatever the subscription data contains,
    and are sent as application/json;
  - one failing channel does not stop the rest.

  The decision and payload helpers are tested directly; the crons themselves
  require PHPMailer and every provider, so the structural checks read their
  source.
*/

require_once WALLOS_ROOT . '/includes/renewal_reminders.php';
require_once WALLOS_ROOT . '/includes/webhook_helper.php';

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

wallos_test('the cron uses the reminder check and records what it reminded', function () {
    $source = file_get_contents(WALLOS_ROOT . '/endpoints/cronjobs/sendnotifications.php');

    assert_contains('isRenewalReminderDue(', $source, 'the cron decides with isRenewalReminderDue()');
    assert_not_contains('$difference === $daysToCompare', $source, 'the exact-day comparison is gone');
    assert_contains('UPDATE subscriptions SET last_notified_payment', $source,
        'the cron records the payment each reminder was for');
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

wallos_test('the crons and the test endpoint share the webhook helpers', function () {
    foreach ([
        'endpoints/cronjobs/sendnotifications.php',
        'endpoints/cronjobs/sendcancellationnotifications.php',
        'endpoints/notifications/testwebhooknotifications.php',
    ] as $file) {
        $source = file_get_contents(WALLOS_ROOT . '/' . $file);

        assert_contains('webhookFillPayload(', $source, $file . ' fills the payload with webhookFillPayload()');
        assert_contains('webhookBuildHeaders(', $source, $file . ' builds headers with webhookBuildHeaders()');
    }
});

wallos_test('every notification channel in the crons is guarded against exceptions', function () {
    foreach ([
        'endpoints/cronjobs/sendnotifications.php' => 11,
        'endpoints/cronjobs/sendcancellationnotifications.php' => 7,
    ] as $file => $channels) {
        $source = file_get_contents(WALLOS_ROOT . '/' . $file);

        preg_match_all('/if \(\$\w+NotificationsEnabled\) \{\s*try \{/', $source, $guarded);
        assert_same($channels, count($guarded[0]), $file . ' wraps each channel in try');
        assert_contains('Error sending email notification to ', $source,
            $file . ' keeps sending to the other recipients when one email fails');
    }
});

wallos_test('cron channels handle provider responses and settings correctly', function () {
    $renewal = file_get_contents(WALLOS_ROOT . '/endpoints/cronjobs/sendnotifications.php');
    $cancellation = file_get_contents(WALLOS_ROOT . '/endpoints/cronjobs/sendcancellationnotifications.php');

    assert_contains('webhookParseHeaders($ntfy["headers"]', $cancellation,
        'cancellation ntfy headers are parsed by the shared helper, so empty headers do not throw');
    assert_not_contains('array_keys($headers)', $cancellation,
        'no array_keys() on a json_decode() result that can be null');
    assert_contains("'days_until' => \$subscription['days']", $renewal,
        '{{days_until}} is the days left for this subscription, not the account default');
    assert_not_contains("\$resultData['code'] == 200) {\n                                    echo \"Mattermost", $renewal,
        'Mattermost success is not read from a JSON body it never sends');
    assert_contains('Mattermost API error: HTTP ', $renewal, 'a Mattermost failure reports its HTTP status');
});
