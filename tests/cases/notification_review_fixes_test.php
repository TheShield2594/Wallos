<?php
/*
  Behaviour fixed after review of the notifications rework (PR #62): the
  template language's edge cases, the dispatcher's isolation, provider
  limits and answers, Apprise's routing and destinations, retries within the
  day, and the preview.
*/

require_once WALLOS_ROOT . '/tests/notification_fixtures.php';
require_once WALLOS_ROOT . '/includes/notifications/apprise_settings.php';
require_once WALLOS_ROOT . '/libs/PHPMailer/PHPMailer.php';
require_once WALLOS_ROOT . '/libs/PHPMailer/Exception.php';

wallos_test('template edge cases: unclosed tags, quoted operators, raw last, bytes counted once', function () {
    assert_same('Unclosed {{.', wallos_template_error('Hi {{ payer'), 'an unclosed expression is rejected');
    assert_same('Unclosed {%.', wallos_template_error('{% if a'), 'and an unclosed tag');
    assert_same('"raw" must be the last filter.', wallos_template_error('{{ a | raw | upper }}'), 'raw only at the end');
    assert_same('A\\"B', wallos_template_render('{{ a | upper }}', ['a' => 'a"b'], 'json'), 'a filtered value is still escaped');

    $context = ['team' => 'Research and Development', 'sign' => 'a>b'];
    assert_same('yes', wallos_template_render('{% if team == "Research and Development" %}yes{% endif %}', $context), '"and" inside quotes is text');
    assert_same('yes', wallos_template_render('{% if sign == "a>b" %}yes{% endif %}', $context), '">" inside quotes is text');
    assert_same('both', wallos_template_render('{% if team and sign == "a>b" %}both{% endif %}', $context), 'operators outside quotes still work');

    $big = str_repeat('z', 30000);
    assert_same($big, wallos_template_render(str_repeat('{% if a %}', 8) . '{{ x }}' . str_repeat('{% endif %}', 8), ['a' => 1, 'x' => $big]),
        'output inside nested blocks is counted once, not once per block');
});

wallos_test('the webhook does not depend on the message template, and one payer failing spares the next', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    $requests = [];
    $env = notification_test_env($db, 1, $requests);
    $webhook = new WallosWebhookChannel(['enabled' => 1, 'url' => 'https://hook.example', 'request_method' => 'POST', 'headers' => '',
        'payload' => '{}', 'cancelation_payload' => '{"cancel":"{{subscription_name}}"}', 'ignore_ssl' => 0], $env);
    // A valid template that renders to nothing for cancellation reminders.
    $templates = ['cancellation' => ['' => ['title' => '', 'body' => '{{ period_summary }}']]];
    $context = ['payer' => 'Ann', 'subscriptions' => [['id' => 7, 'name' => 'Gym', 'key' => 'cancel:7']]];

    assert_same(['cancel:7' => true], wallos_dispatch_notifications($env, [$webhook], 'cancellation', [$context], $templates),
        'the webhook still sends its own payload');
    assert_same('{"cancel":"Gym"}', $requests[0]['body'], 'filled from its cancellation payload');

    $calls = 0;
    $flaky = new class(['enabled' => 1], $env) extends WallosNotificationChannel {
        const NAME = 'gotify';
        const LABEL = 'Flaky';
        public $calls = 0;
        protected function send(array $message, array $context)
        {
            if (++$this->calls === 1) {
                throw new RuntimeException('first payer breaks');
            }
            return true;
        }
    };
    $contexts = [
        ['payer' => 'Ann', 'subscriptions' => [['name' => 'A', 'key' => 'payment:1']]],
        ['payer' => 'Bob', 'subscriptions' => [['name' => 'B', 'key' => 'payment:2']]],
    ];
    assert_same(['payment:2' => true], wallos_dispatch_notifications($env, [$flaky], 'payment', $contexts, []),
        'the second payer is still sent to after the first one throws');
    $db->close();
});

wallos_test('a digest too large for one message is sent in parts, every reminder covered', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    $requests = [];
    $env = notification_test_env($db, 1, $requests);
    $gotify = new WallosGotifyChannel(['enabled' => 1, 'url' => 'https://gotify.example', 'token' => 't'], $env);

    $subscriptions = [];
    for ($i = 1; $i <= WALLOS_TEMPLATE_MAX_ITERATIONS + 1; $i++) {
        $subscriptions[] = ['name' => 'S' . $i, 'formatted_price' => '€ 1', 'days_text' => 'Today', 'key' => 'payment:' . $i];
    }
    $delivered = wallos_dispatch_notifications($env, [$gotify], 'payment', [['payer' => '', 'subscriptions' => $subscriptions]], []);

    assert_same(WALLOS_TEMPLATE_MAX_ITERATIONS + 1, count($delivered), 'every reminder is delivered');
    assert_true(count($requests) >= 2, 'in more than one message');
    $db->close();
});

wallos_test('provider limits and answers: Discord, Telegram, Pushover, ntfy, Serverchan', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    $context = ['payer' => 'Ann', 'subscriptions' => [['name' => 'A', 'key' => 'payment:1']]];
    $long = implode("\n", array_fill(0, 300, str_repeat('x', 20)));

    $requests = [];
    $discord = new WallosDiscordChannel(['enabled' => 1, 'webhook_url' => 'https://discord.example/api/webhooks/1/a?thread_id=9'], notification_test_env($db, 1, $requests));
    assert_same(['payment:1'], $discord->sendForPayer(['title' => 'Bills', 'body' => $long, 'custom_title' => true], $context), 'delivered');
    assert_true(count($requests) > 1, 'a body over 2000 characters goes out in several messages');
    foreach ($requests as $request) {
        assert_true(mb_strlen(json_decode($request['body'], true)['content']) <= 2000, 'each within the limit');
    }
    assert_same('https://discord.example/api/webhooks/1/a?thread_id=9&wait=true', $requests[0]['url'], 'wait=true is added, the thread kept');
    assert_same("Bills\n\n", substr(json_decode($requests[0]['body'], true)['content'], 0, 7), 'the custom title leads the message');

    $requests = [];
    $discord->sendForPayer(['title' => 'Tally Notification', 'body' => 'Short', 'custom_title' => false], $context);
    assert_same('Short', json_decode($requests[0]['body'], true)['content'], 'the default message is unchanged');

    $requests = [];
    $telegram = new WallosTelegramChannel(['enabled' => 1, 'bot_token' => 'b', 'chat_id' => 'c'], notification_test_env($db, 1, $requests));
    $telegram->sendForPayer(['title' => 'Bills', 'body' => 'Body', 'custom_title' => true], $context);
    assert_same("Bills\n\nBody", json_decode($requests[0]['body'], true)['text'], 'Telegram shows the custom title');

    $requests = [];
    $pushover = new WallosPushoverChannel(['enabled' => 1, 'token' => 't', 'user_key' => 'u'], notification_test_env($db, 1, $requests));
    $pushover->sendForPayer(['title' => str_repeat('é', 300), 'body' => 'Body', 'custom_title' => true], $context);
    parse_str($requests[0]['body'], $fields);
    assert_same(250, mb_strlen($fields['title']), 'the Pushover title is cut to 250 characters, whole characters');

    $requests = [];
    $ntfy = new WallosNtfyChannel(['enabled' => 1, 'host' => 'https://ntfy.example/', 'topic' => '/renewals?priority=high', 'headers' => ''], notification_test_env($db, 1, $requests));
    $ntfy->sendForPayer(['title' => 'Due soon', 'body' => 'Body', 'custom_title' => true], $context);
    assert_same('https://ntfy.example/renewals?priority=high&title=Due%20soon', $requests[0]['url'], 'one slash, and the title joins the query');

    $requests = [];
    $serverchan = new WallosServerchanChannel(['enabled' => 1, 'sendkey' => 'SCTx'],
        notification_test_env($db, 1, $requests, ['ok' => true, 'code' => 200, 'body' => '{"code":40001,"message":"bad key"}', 'error' => '']));
    assert_same([], $serverchan->sendForPayer(['title' => 'T', 'body' => 'B', 'custom_title' => false], $context), 'a Serverchan error code is not a delivery');
    $serverchan = new WallosServerchanChannel(['enabled' => 1, 'sendkey' => 'SCTx'],
        notification_test_env($db, 1, $requests, ['ok' => true, 'code' => 200, 'body' => '{"code":0}', 'error' => '']));
    assert_same(['payment:1'], $serverchan->sendForPayer(['title' => 'T', 'body' => 'B', 'custom_title' => false], $context), 'code 0 is');
    $db->close();
});

wallos_test('Apprise: 204 is not a delivery, routes keep a path prefix, private destinations are refused', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 2, 'bob');
    $context = ['payer' => 'Ann', 'subscriptions' => [['name' => 'A', 'key' => 'payment:1']]];
    $message = ['title' => 'T', 'body' => 'B', 'custom_title' => false];

    $requests = [];
    $apprise = new WallosAppriseChannel(['enabled' => 1, 'url' => 'https://apprise.example', 'mode' => 'key', 'config_key' => 'k'],
        notification_test_env($db, 2, $requests, ['ok' => true, 'code' => 204, 'body' => '', 'error' => '']));
    assert_same([], $apprise->sendForPayer($message, $context), 'HTTP 204 (an empty configuration) sent nothing');

    assert_same('https://h.example/apprise/notify/k?tenant=blue', WallosAppriseChannel::routeUrl('https://h.example/apprise/?tenant=blue', '/notify/k'),
        'the route goes on the path, before the query');
    assert_same('http://apprise:8000/notify/', WallosAppriseChannel::routeUrl('http://apprise:8000', '/notify/'), 'a plain URL');

    $resolve = fn($host) => ['internal.example' => '10.0.0.5', 'public.example' => '93.184.216.34'][$host] ?? null;
    assert_same(['internal.example', '169.254.169.254'], WallosAppriseChannel::blockedDestinations([
        'json://internal.example/hook', 'jsons://public.example/hook', 'tgram://123:abc/456', 'json://169.254.169.254/latest',
    ], $db, 2, $resolve), 'private and metadata destinations are refused; tokens and public hosts pass');

    $requests = [];
    $env = notification_test_env($db, 2, $requests);
    $env->destinationResolver = $resolve;
    $stateless = new WallosAppriseChannel(['enabled' => 1, 'url' => 'https://apprise.example', 'mode' => 'urls', 'urls' => 'json://internal.example/x'], $env);
    assert_same([], $stateless->sendForPayer($message, $context), 'a private destination is not sent to');
    assert_same([], $requests, 'the Apprise server is not even called');

    assert_same('fill_mandatory_fields', wallos_apprise_settings_from_input(['enabled' => 1, 'url' => 'https://a.example', 'mode' => 'urls', 'urls' => ','])['error'],
        'a list that parses to no destination is refused');
    assert_same(null, wallos_apprise_settings_from_input(['enabled' => 1, 'url' => 'https://a.example', 'mode' => 'key', 'config_key' => 'k', 'urls' => str_repeat('x', 9000)])['error'],
        'the hidden list does not block key mode');
    assert_same('saved', wallos_apprise_with_stored_urls(['urls' => ''], ['urls' => 'saved'])['urls'], 'a blank field keeps the saved destinations');
    assert_same('', wallos_apprise_with_stored_urls(['urls' => '', 'clear_urls' => 1], ['urls' => 'saved'])['urls'], 'unless cleared');
    $db->close();
});

wallos_test('a rejected CC does not make an accepted payer email a failure', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    $requests = [];
    $make = function ($failed) use ($db, &$requests) {
        $env = notification_test_env($db, 1, $requests);
        $env->mailerFactory = function () use ($failed) {
            return new class($failed) extends PHPMailer\PHPMailer\PHPMailer {
                private $failed;
                public function __construct($failed) { parent::__construct(true); $this->failed = $failed; }
                public function send() { throw new PHPMailer\PHPMailer\Exception('SMTP Error: The following recipients failed: ' . $this->failed, self::STOP_CONTINUE); }
            };
        };
        return new WallosEmailChannel(['enabled' => 1, 'smtp_address' => 'smtp.example', 'smtp_port' => 587, 'encryption' => 'tls',
            'smtp_username' => '', 'smtp_password' => '', 'from_email' => '', 'other_emails' => 'cc@example.com'], $env);
    };
    $context = ['payer' => 'Ann', 'payer_email' => 'ann@example.com', 'subscriptions' => [['name' => 'A', 'key' => 'payment:1']]];
    $message = ['title' => 'T', 'body' => 'B', 'custom_title' => false];

    assert_same(['payment:1'], $make('cc@example.com: mailbox unavailable')->sendForPayer($message, $context), 'only the CC failed: delivered');
    assert_same([], $make('ann@example.com: mailbox unavailable')->sendForPayer($message, $context), 'the payer failed: not delivered');
    $db->close();
});

wallos_test('an undelivered reminder is retried on the next runs that day, a bounded number of times', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    $now = new DateTime('2026-11-18 09:00');

    for ($attempt = 1; $attempt < WALLOS_NOTIFICATION_MAX_ATTEMPTS; $attempt++) {
        wallos_mark_notification_run($db, 1, 'last_payment_run', '2026-11-18', false);
        assert_true(wallos_notification_slot_due('09:00', wallos_notification_settings_row($db, 1)['last_payment_run'] ?? null, $now),
            'after failed attempt ' . $attempt . ' the day stays open');
    }
    wallos_mark_notification_run($db, 1, 'last_payment_run', '2026-11-18', false);
    assert_same('2026-11-18', wallos_notification_settings_row($db, 1)['last_payment_run'], 'after the last attempt the day closes');

    wallos_mark_notification_run($db, 1, 'last_cancellation_run', '2026-11-19', true);
    assert_same('2026-11-19', wallos_notification_settings_row($db, 1)['last_cancellation_run'], 'a complete run closes it at once');

    foreach (['sendnotifications.php', 'sendcancellationnotifications.php'] as $cron) {
        $source = file_get_contents(WALLOS_ROOT . '/endpoints/cronjobs/' . $cron);
        assert_contains('wallos_notification_settings_row($db, $userId)', $source, $cron . ' reads each account\'s settings when its turn comes');
        assert_contains(', $today, $complete);', $source, $cron . ' leaves the day open when something was not delivered');
    }
    $db->close();
});

wallos_test('a failed delivery is due again the next day; the delivered one is not', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    notification_test_household($db, 1, 1, 'Alice');
    $today = '2026-11-18';
    $due = notification_test_subscription($db, 1, ['name' => 'Due', 'next_payment' => '2026-11-21', 'notify_days_before' => 3]);
    notification_test_subscription($db, 1, ['name' => 'Other', 'next_payment' => '2026-11-21', 'notify_days_before' => 3]);

    [, $pending] = wallos_collect_payment_reminders($db, 1, ['days' => 1], $today, wallos_notification_lookups($db, 1));
    wallos_record_payment_reminders($db, 1, $pending, ['payment:' . $due => true]);

    [$entries] = wallos_collect_payment_reminders($db, 1, ['days' => 1], '2026-11-19', wallos_notification_lookups($db, 1));
    assert_same(['Other'], array_column($entries[1] ?? [], 'name'), 'the next day only the undelivered one is due');
    assert_same([[(int) $due, '2026-11-21', 3]], array_map(fn($row) => [(int) $row[0], $row[1], (int) $row[2]],
        notification_review_rows($db, 'SELECT subscription_id, due_date, "offset" FROM notification_log')), 'and only the delivered offset is logged');
    $db->close();
});

wallos_test('cancellation and trial reminders of one subscription are marked on their own', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    notification_test_household($db, 1, 1, 'Alice');
    $today = '2026-11-18';
    $id = notification_test_subscription($db, 1, ['name' => 'Trial', 'next_payment' => '2026-12-15',
        'cancel_notice_mode' => 'fixed', 'cancellation_date' => $today,
        'intro_type' => 'trial', 'intro_price' => 0, 'intro_end_date' => '2026-11-20', 'intro_notify_days' => 3]);

    [$entries, $markers] = wallos_collect_cancellation_reminders($db, 1, $today, wallos_notification_lookups($db, 1));
    assert_same(2, count($entries[1] ?? []), 'both reminders are due');
    // A webhook that accepted the cancellation reminder and rejected the trial one.
    wallos_record_cancellation_reminders($db, 1, $markers, [wallos_notification_key('cancel', $id) => true]);
    assert_same($today, $db->querySingle('SELECT last_cancel_notified_for FROM subscriptions WHERE id = ' . $id), 'the delivered one is marked');
    assert_same(null, $db->querySingle('SELECT intro_notified_for FROM subscriptions WHERE id = ' . $id), 'the rejected one is not');
    $db->close();
});

wallos_test('the preview shows one payer, counts cancellations to the cancel-by date, on a fixed date', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    notification_test_household($db, 1, 1, 'Alice');
    notification_test_household($db, 1, 2, 'Bob');
    $today = '2026-06-01';
    notification_test_subscription($db, 1, ['name' => 'Ann first', 'next_payment' => '2026-06-03', 'payer_user_id' => 1]);
    notification_test_subscription($db, 1, ['name' => 'Bob second', 'next_payment' => '2026-06-04', 'payer_user_id' => 2]);
    notification_test_subscription($db, 1, ['name' => 'Contract', 'next_payment' => '2026-07-01', 'payer_user_id' => 1,
        'cancel_notice_mode' => 'fixed', 'cancellation_date' => '2026-06-24']);

    $payment = wallos_notification_preview_context($db, 1, 'payment', $today);
    assert_same(['Ann first', 'Contract'], array_column($payment['subscriptions'], 'name'), 'only the first payer\'s rows');
    assert_same('In 2 days', $payment['subscriptions'][0]['days_text'], 'days counted from the given date');

    $cancellation = wallos_notification_preview_context($db, 1, 'cancellation', $today);
    $contract = array_values(array_filter($cancellation['subscriptions'], fn($row) => $row['name'] === 'Contract'))[0];
    assert_same(23, $contract['days'], 'a cancellation counts down to its cancel-by date, not the renewal');
    $db->close();
});

function notification_review_rows(SQLite3 $db, $sql)
{
    $rows = [];
    $result = $db->query($sql);
    while ($result && ($row = $result->fetchArray(SQLITE3_NUM))) {
        $rows[] = $row;
    }

    return $rows;
}
