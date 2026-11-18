<?php
/*
  The shared AI recommendations prompt (includes/ai_recommendations.php, issue #42).
*/

require_once WALLOS_ROOT . '/includes/ai_recommendations.php';

wallos_test('the AI url_host is the bare public domain of the subscription URL', function () {
    assert_same('realdebrid.com', wallos_ai_url_host('https://www.realdebrid.com/account?token=secret'), 'no www, path or query');
    assert_same('example.co.uk', wallos_ai_url_host('example.co.uk/path'), 'a URL without a scheme');
    assert_same('example.com', wallos_ai_url_host('https://user:pass@Example.COM:8443/'), 'no credentials or port, lower case');
    assert_same('', wallos_ai_url_host('http://192.168.1.10/'), 'not an IP address');
    assert_same('', wallos_ai_url_host('http://nas:5000/'), 'not a single-label name');
    assert_same('', wallos_ai_url_host(''), 'empty');
    assert_same('', wallos_ai_url_host(null), 'not a string');
});

wallos_test('subscription descriptions are one line and at most 200 characters', function () {
    assert_same('A debrid service', wallos_normalize_subscription_description("  A  debrid\nservice "), 'collapsed');
    assert_same(200, mb_strlen(wallos_normalize_subscription_description(str_repeat('é', 300))), 'capped');
    assert_same('', wallos_normalize_subscription_description(['x']), 'not a string');
});

wallos_test('the AI payload adds url_host and description only when allowed and present', function () {
    $subscriptions = [
        ['name' => 'RD', 'price' => 3.0, 'currency_id' => 1, 'cycle' => 3, 'frequency' => 1, 'category_id' => 2, 'payer_user_id' => 5,
            'url' => 'https://real-debrid.com', 'description' => 'Premium downloader', 'notes' => 'account 12345', 'inactive' => 0],
        ['name' => 'Plain', 'price' => 10, 'currency_id' => 9, 'cycle' => 4, 'frequency' => 2, 'category_id' => 7, 'payer_user_id' => 6,
            'url' => '', 'description' => '', 'inactive' => 0],
        ['name' => 'Off', 'price' => 1, 'currency_id' => 1, 'cycle' => 3, 'frequency' => 1, 'category_id' => 2, 'payer_user_id' => 5, 'inactive' => 1],
    ];
    $currencies = [1 => ['code' => 'EUR']];
    $categories = [2 => ['name' => 'Tools']];
    $members = [5 => ['name' => 'Sam']];

    $payload = wallos_ai_build_payload($subscriptions, $currencies, $categories, $members, true);
    assert_same(2, count($payload), 'inactive subscriptions are left out');
    assert_same('real-debrid.com', $payload[0]['url_host'], 'the domain');
    assert_same('Premium downloader', $payload[0]['description'], 'the description');
    assert_true(!array_key_exists('notes', $payload[0]), 'notes are never sent');
    assert_true(!array_key_exists('url_host', $payload[1]) && !array_key_exists('description', $payload[1]), 'absent hints are left out');
    assert_same('10', $payload[1]['price'], 'price without a known currency');
    assert_same('Every 2 years', $payload[1]['frequency'], 'frequency');
    assert_same('Uncategorized', $payload[1]['category'], 'unknown category');
    assert_same('Unknown', $payload[1]['payer'], 'unknown payer');

    $off = wallos_ai_build_payload($subscriptions, $currencies, $categories, $members, false);
    assert_true(!array_key_exists('url_host', $off[0]) && !array_key_exists('description', $off[0]), 'switched off: no hints');

    $prompt = wallos_ai_recommendations_prompt($payload, 'English', true);
    assert_contains('trust it over your own assumptions', $prompt, 'the prompt tells the model to trust descriptions');
    assert_contains('"description": "Premium downloader"', $prompt, 'the data follows the prompt');
    assert_not_contains('trust it over your own assumptions', wallos_ai_recommendations_prompt($off, 'English', false), 'no rule without descriptions');
});

wallos_test('the AI migration adds the description column and the include_descriptions switch on by default', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    $db->exec("INSERT INTO ai_settings (user_id, type, enabled, api_key, model, url, run_schedule) VALUES (1, 'ollama', 1, '', 'm', '', 'manual')");
    assert_same(1, (int) $db->querySingle('SELECT include_descriptions FROM ai_settings WHERE user_id = 1'), 'on by default');
    $db->exec("INSERT INTO subscriptions (id, user_id, name, price, currency_id, next_payment, cycle, frequency) VALUES (9, 1, 'x', 1, 1, '2026-01-01', 3, 1)");
    assert_same('', $db->querySingle('SELECT description FROM subscriptions WHERE id = 9'), 'empty by default');
    $db->close();
});
