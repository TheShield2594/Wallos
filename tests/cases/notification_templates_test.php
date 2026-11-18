<?php
/*
  Notification message templates and the shared dispatcher (issue #37).
*/

require_once WALLOS_ROOT . '/tests/notification_fixtures.php';

/**
 * The message text the payment cron built before templates existed, kept
 * here to pin the default template to it.
 */
function legacy_payment_message($name, $perUser, $summary, $includeSummary)
{
    if (empty($perUser) && !$includeSummary) {
        return '';
    }
    if (empty($perUser)) {
        return ($name ? $name . ', ' : '') . $summary . "\n";
    }
    $message = $name ? $name . ", the following subscriptions are up for renewal:\n" : "The following subscriptions are up for renewal:\n";
    foreach ($perUser as $subscription) {
        $days = $subscription['days'];
        $dayText = $days == 0 ? 'Today' : ($days == 1 ? 'Tomorrow' : 'In ' . $days . ' days');
        $message .= $subscription['name'] . ' for ' . $subscription['formatted_price'] . ' (' . $dayText . ")\n";
    }
    if ($includeSummary) {
        $message .= "\n" . $summary . "\n";
    }

    return $message;
}

wallos_test('the template language renders variables, filters, loops and conditions', function () {
    $context = [
        'payer' => 'Ann',
        'subscriptions' => [['name' => 'Netflix', 'days' => 0], ['name' => 'Spotify', 'days' => 3]],
        'empty' => '',
    ];

    assert_same('Hi Ann', wallos_template_render('Hi {{ payer }}', $context), 'a variable');
    assert_same('ANN ann Ann', wallos_template_render('{{ payer | upper }} {{ payer | lower }} {{ payer | capitalize }}', $context), 'case filters');
    assert_same('none', wallos_template_render('{{ empty | default("none") }}', $context), 'default()');
    assert_same('', wallos_template_render('{{ missing }}{{ subscriptions.9.name }}', $context), 'unknown variables are empty');
    assert_same('1. Netflix (today), 2. Spotify (3)',
        wallos_template_render('{% for s in subscriptions %}{{ loop.index }}. {{ s.name }} ({% if s.days == 0 %}today{% else %}{{ s.days }}{% endif %}){% if not loop.last %}, {% endif %}{% endfor %}', $context),
        'a loop with loop.index, loop.last and if/else');
    assert_same('b', wallos_template_render('{% if payer == "Bob" %}a{% elif payer and subscriptions %}b{% else %}c{% endif %}', $context), 'elif and "and"');
    assert_same('yes', wallos_template_render('{% if missing or payer %}yes{% endif %}', $context), '"or"');
    assert_same('ok', wallos_template_render('{# a comment #}ok', $context), 'comments render as nothing');
    assert_same('many', wallos_template_render('{% if subscriptions > 1 %}many{% endif %}', $context), 'a list compares by its length');
});

wallos_test('output is escaped for the channel unless marked raw', function () {
    $context = ['name' => 'Say "hi" & <b>'];

    assert_same('Say \\"hi\\" & <b>', wallos_template_render('{{ name }}', $context, 'json'), 'JSON escaping inside a JSON string');
    assert_same('Say &quot;hi&quot; &amp; &lt;b&gt;', wallos_template_render('{{ name }}', $context, 'html'), 'HTML escaping');
    assert_same('Say "hi" & <b>', wallos_template_render('{{ name | raw }}', $context, 'json'), '| raw skips it');
    assert_same('Say "hi" & <b>', wallos_template_render('{{ name }}', $context, 'none'), 'plain text is left alone');
    assert_same('Say%20%22hi%22%20%26%20%3Cb%3E', wallos_template_render('{{ name | url }}', $context, 'json'), 'an explicit filter is not escaped twice');
});

wallos_test('broken templates are rejected with a reason', function () {
    foreach ([
        '{% if %}x{% endif %}' => 'Empty condition',
        '{% for x %}{% endfor %}' => 'Invalid loop',
        '{{ a | nope }}' => 'Unknown filter',
        '{% if a %}x' => 'Missing {% endif %}',
        '{% endfor %}' => 'Unknown tag',
        '{{ a b }}' => 'Invalid expression',
        '{% include "x" %}' => 'Unknown tag',
    ] as $template => $reason) {
        $error = wallos_template_error($template);
        assert_true($error !== null && strpos($error, $reason) === 0, $template . ' is rejected: ' . var_export($error, true));
    }
    assert_same(null, wallos_template_error(wallos_default_notification_template('payment')['body']), 'the payment default compiles');
    assert_same(null, wallos_template_error(wallos_default_notification_template('cancellation')['body']), 'the cancellation default compiles');
});

wallos_test('runaway templates hit a limit instead of running away', function () {
    $huge = str_repeat('x', WALLOS_TEMPLATE_MAX_LENGTH + 1);
    assert_true(wallos_template_error($huge) !== null, 'an over-long template is rejected');

    $nested = str_repeat('{% if a %}', WALLOS_TEMPLATE_MAX_DEPTH + 2) . str_repeat('{% endif %}', WALLOS_TEMPLATE_MAX_DEPTH + 2);
    assert_true(wallos_template_error($nested) !== null, 'nesting too deep is rejected');

    $items = array_fill(0, 60, 1);
    $threw = false;
    try {
        wallos_template_render('{% for a in items %}{% for b in items %}{{ b }}{% endfor %}{% endfor %}', ['items' => $items]);
    } catch (WallosTemplateException $e) {
        $threw = true;
    }
    assert_true($threw, 'too many loop iterations stop rendering');

    $threw = false;
    try {
        wallos_template_render('{% for a in items %}{{ big }}{% endfor %}', ['items' => array_fill(0, 100, 1), 'big' => str_repeat('y', 5000)]);
    } catch (WallosTemplateException $e) {
        $threw = true;
    }
    assert_true($threw, 'an over-long message stops rendering');
});

wallos_test('the default templates reproduce the messages sent before templates existed', function () {
    $subscriptions = [
        ['name' => 'Net"flix', 'formatted_price' => '€ 9.99', 'days' => 0, 'days_text' => 'Today'],
        ['name' => 'Spotify', 'formatted_price' => '$ 1.00', 'days' => 1, 'days_text' => 'Tomorrow'],
        ['name' => 'Cloud', 'formatted_price' => '$ 2.00', 'days' => 5, 'days_text' => 'In 5 days'],
    ];
    foreach ([['Ann', $subscriptions, true], ['', $subscriptions, false], ['Ann', $subscriptions, false], ['Ann', [], true], ['', [], true]] as [$payer, $list, $summary]) {
        foreach (['discord', 'email', 'telegram'] as $channel) {
            $context = ['payer' => $payer, 'subscriptions' => $list, 'period_summary' => 'Amount for this pay period: € 5', 'include_period_summary' => $summary];
            $expected = legacy_payment_message($channel === 'email' ? '' : $payer, $list, 'Amount for this pay period: € 5', $summary);
            assert_same($expected, wallos_render_notification_message('payment', $channel, $context, [])['body'],
                'payment, ' . $channel . ', payer "' . $payer . '", ' . count($list) . ' rows');
        }
    }

    $context = ['payer' => 'Ann', 'subscriptions' => [['name' => 'Gym', 'price' => '30€', 'deadline_note' => ' (cancel by 2026-12-01 to avoid the renewal on 2026-12-15)']]];
    assert_same("Ann, the following subscriptions are up for cancellation:\nGym for 30€ (cancel by 2026-12-01 to avoid the renewal on 2026-12-15)\n",
        wallos_render_notification_message('cancellation', 'gotify', $context, [])['body'], 'the cancellation message');
    assert_same("The following subscriptions are up for cancellation:\nGym for 30€ (cancel by 2026-12-01 to avoid the renewal on 2026-12-15)\n",
        wallos_render_notification_message('cancellation', 'email', $context, [])['body'], 'the cancellation email has no greeting');

    assert_same('Tally Notification', wallos_render_notification_message('payment', 'email', $context, [])['title'], 'the payment email subject');
    assert_same('Tally Cancellation Notification', wallos_render_notification_message('cancellation', 'email', $context, [])['title'], 'the cancellation email subject');
});

wallos_test('a channel override beats the account template, which beats the default', function () {
    $templates = [
        'payment' => [
            '' => ['title' => 'Bills for {{ payer }}', 'body' => 'All: {% for s in subscriptions %}{{ s.name }}{% endfor %}'],
            'telegram' => ['title' => '', 'body' => 'TG {% for s in subscriptions %}{{ s.name | upper }}{% endfor %}'],
        ],
    ];
    $context = ['payer' => 'Ann', 'subscriptions' => [['name' => 'x']]];

    $telegram = wallos_render_notification_message('payment', 'telegram', $context, $templates);
    assert_same('TG X', $telegram['body'], 'the channel override is used for its channel');
    assert_same('Bills for Ann', $telegram['title'], 'a blank override title falls back to the account template');
    assert_true($telegram['custom_title'], 'and counts as a custom title');

    $discord = wallos_render_notification_message('payment', 'discord', $context, $templates);
    assert_same('All: x', $discord['body'], 'other channels use the account template');

    $cancellation = wallos_render_notification_message('cancellation', 'discord', ['payer' => '', 'subscriptions' => []], $templates);
    assert_same('Tally Cancellation Notification', $cancellation['title'], 'the other kind keeps its default');
    assert_true(!$cancellation['custom_title'], 'which is not a custom title');

    $sameTitle = ['payment' => ['' => ['title' => 'Tally Notification', 'body' => 'Edited']]];
    assert_true(!wallos_render_notification_message('payment', 'gotify', $context, $sameTitle)['custom_title'],
        'the default title saved along with an edited body is not a custom title');
});

wallos_test('a template that fails while sending falls back to the default message', function () {
    $templates = ['cancellation' => ['' => ['title' => 'T', 'body' => '{% for a in s %}{% for b in s %}{% for c in s %}.{% endfor %}{% endfor %}{% endfor %}']]];
    $context = ['payer' => '', 's' => array_fill(0, 20, 1), 'subscriptions' => [['name' => 'Gym', 'price' => '1€', 'deadline_note' => '']]];
    $message = wallos_render_notification_message('cancellation', 'gotify', $context, $templates);

    assert_true($message['error'] !== null, 'the error is reported');
    assert_same("The following subscriptions are up for cancellation:\nGym for 1€\n", $message['body'], 'the default message is sent instead');
});

wallos_test('every channel reaches cancellation reminders too, Web Push and Apprise included', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    foreach ([
        'discord_notifications' => ['webhook_url' => 'https://discord.example/x'],
        'gotify_notifications' => ['url' => 'https://gotify.example', 'token' => 't'],
        'telegram_notifications' => ['bot_token' => 'b', 'chat_id' => 'c'],
        'pushplus_notifications' => ['token' => 'p'],
        'mattermost_notifications' => ['webhook_url' => 'https://mm.example/x'],
        'pushover_notifications' => ['user_key' => 'u', 'token' => 't'],
        'ntfy_notifications' => ['host' => 'https://ntfy.example', 'topic' => 't'],
        'serverchan_notifications' => ['sendkey' => 'SCTx'],
        'apprise_notifications' => ['url' => 'https://apprise.example', 'mode' => 'key', 'config_key' => 'abc'],
        'push_notifications' => [],
    ] as $table => $columns) {
        notification_test_enable($db, $table, 1, $columns);
    }
    // The webhook takes part in cancellation reminders only with its own payload.
    notification_test_enable($db, 'webhook_notifications', 1, ['url' => 'https://hook.example', 'payload' => '{}', 'cancelation_payload' => '']);
    $db->exec("INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth) VALUES (1, 'https://push.example/1', 'k', 'a')");

    $requests = [];
    $env = notification_test_env($db, 1, $requests);
    $names = fn($channels) => array_map(fn($channel) => $channel::NAME, $channels);

    $cancellation = $names(wallos_load_notification_channels($env, 'cancellation'));
    assert_same(['discord', 'gotify', 'telegram', 'pushplus', 'mattermost', 'pushover', 'ntfy', 'serverchan', 'apprise', 'push'], $cancellation,
        'cancellation reminders go through every enabled channel');
    assert_true(in_array('webhook', $names(wallos_load_notification_channels($env, 'payment')), true), 'payment reminders use the webhook');

    $db->exec("UPDATE webhook_notifications SET cancelation_payload = '{}' WHERE user_id = 1");
    assert_true(in_array('webhook', $names(wallos_load_notification_channels($env, 'cancellation')), true),
        'and cancellation reminders once it has a cancellation payload');

    $db->exec('DELETE FROM push_subscriptions');
    assert_true(!in_array('push', $names(wallos_load_notification_channels($env, 'payment')), true), 'push without a device is skipped');

    $db->exec('UPDATE gotify_notifications SET enabled = 0');
    assert_true(!in_array('gotify', $names(wallos_load_notification_channels($env, 'payment')), true), 'a disabled channel is skipped');
    $db->close();
});

wallos_test('each payer gets one rendered message per channel, with the user template', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    $requests = [];
    $env = notification_test_env($db, 1, $requests);
    $gotify = new WallosGotifyChannel(['enabled' => 1, 'url' => 'https://gotify.example/', 'token' => 'tok', 'ignore_ssl' => 1], $env);
    $templates = ['payment' => ['' => ['title' => 'Due for {{ payer }}', 'body' => '{% for s in subscriptions %}{{ s.name }};{% endfor %}']]];

    $contexts = [
        ['payer' => 'Ann', 'subscriptions' => [['name' => 'A', 'key' => 'payment:1']]],
        ['payer' => 'Bob', 'subscriptions' => [['name' => 'B', 'key' => 'payment:2'], ['name' => 'C', 'key' => 'payment:3']]],
    ];
    $delivered = wallos_dispatch_notifications($env, [$gotify], 'payment', $contexts, $templates);

    assert_same(['payment:1' => true, 'payment:2' => true, 'payment:3' => true], $delivered, 'every reminder in a delivered message is delivered');
    assert_same(2, count($requests), 'one request per payer');
    assert_same(['message' => 'A;', 'priority' => 5, 'title' => 'Due for Ann'], json_decode($requests[0]['body'], true), 'the custom title and body');
    assert_same('https://gotify.example/message?token=tok', $requests[0]['url'], 'to the Gotify message endpoint');
    assert_true($requests[0]['ignore_ssl'], 'with the channel\'s SSL setting');
    $db->close();
});

wallos_test('the preview renders the account\'s own subscriptions', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    notification_test_household($db, 1, 1, 'Alice');
    $today = '2026-06-01';
    notification_test_subscription($db, 1, ['name' => 'Netflix', 'price' => 12.5, 'next_payment' => notification_test_date(2, $today)]);

    $context = wallos_notification_preview_context($db, 1, 'payment', $today);
    $message = wallos_render_notification_message('payment', 'discord', $context, []);
    assert_contains('Alice, the following subscriptions are up for renewal:', $message['body'], 'greets the payer');
    assert_contains('Netflix for', $message['body'], 'lists the real subscription');
    assert_contains('(In 2 days)', $message['body'], 'with its days left');

    $db->exec('DELETE FROM subscriptions');
    $context = wallos_notification_preview_context($db, 1, 'cancellation', $today);
    assert_same(['Streaming', 'Cloud storage'], array_column($context['subscriptions'], 'name'), 'an empty account gets sample rows');
    $db->close();
});

wallos_test('templates are stored per account, kind and channel', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    $db->exec("INSERT INTO notification_templates (user_id, kind, channel, title, body) VALUES (1, 'payment', NULL, 'A', 'a')");
    $db->exec("INSERT INTO notification_templates (user_id, kind, channel, title, body) VALUES (1, 'payment', 'email', 'B', 'b')");
    $duplicate = @$db->exec("INSERT INTO notification_templates (user_id, kind, channel, title, body) VALUES (1, 'payment', NULL, 'C', 'c')");
    assert_true(!$duplicate, 'one account template per kind');

    assert_same(['payment' => ['' => ['title' => 'A', 'body' => 'a'], 'email' => ['title' => 'B', 'body' => 'b']]],
        wallos_notification_templates_load($db, 1), 'loaded by kind and channel');
    assert_same([], wallos_notification_templates_load($db, 2), 'another account has none');

    $source = file_get_contents(WALLOS_ROOT . '/endpoints/notifications/savenotificationtemplate.php');
    assert_contains('wallos_notification_template_error(', $source, 'a template is validated before it is saved');
    $db->close();
});
