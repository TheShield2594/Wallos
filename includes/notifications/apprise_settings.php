<?php
/*
  Validation of the Apprise settings form (issue #38).
*/

require_once __DIR__ . '/channel.php';
require_once __DIR__ . '/channels/apprise.php';

/**
 * @param array $input  enabled, url, mode ('key'|'urls'), config_key, urls, tags, ignore_ssl
 * @param bool  $forTest the test button needs a destination even when disabled
 * @return array{error: string|null, fields: array}
 */
function wallos_apprise_settings_from_input(array $input, $forTest = false)
{
    $fields = [
        'enabled' => !empty($input['enabled']) ? 1 : 0,
        'url' => trim((string) ($input['url'] ?? '')),
        'mode' => ($input['mode'] ?? 'key') === 'urls' ? 'urls' : 'key',
        'config_key' => trim((string) ($input['config_key'] ?? '')),
        'urls' => trim(str_replace("\r\n", "\n", (string) ($input['urls'] ?? ''))),
        'tags' => trim((string) ($input['tags'] ?? '')),
        'ignore_ssl' => !empty($input['ignore_ssl']) ? 1 : 0,
    ];

    $needed = $fields['enabled'] || $forTest;
    if ($fields['url'] === '' && $needed) {
        return ['error' => 'fill_mandatory_fields', 'fields' => []];
    }
    if ($fields['url'] !== '') {
        $scheme = strtolower((string) parse_url($fields['url'], PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true) || !filter_var($fields['url'], FILTER_VALIDATE_URL)) {
            return ['error' => 'error', 'fields' => []];
        }
    }
    if ($fields['mode'] === 'key') {
        if ($fields['config_key'] === '' && $needed) {
            return ['error' => 'fill_mandatory_fields', 'fields' => []];
        }
        if ($fields['config_key'] !== '' && !preg_match('/^[A-Za-z0-9_-]{1,128}$/', $fields['config_key'])) {
            return ['error' => 'apprise_invalid_key', 'fields' => []];
        }
    } elseif ($needed && WallosAppriseChannel::parseUrls($fields['urls']) === []) {
        // The same parser the sender uses: "," alone is no destination.
        return ['error' => 'fill_mandatory_fields', 'fields' => []];
    }
    if ($fields['mode'] === 'urls' && strlen($fields['urls']) > 8000) {
        return ['error' => 'error', 'fields' => []];
    }
    if ($fields['mode'] === 'key') {
        // The list is hidden in key mode: not stored, not checked.
        $fields['urls'] = '';
    }
    if (strlen($fields['tags']) > 500) {
        return ['error' => 'error', 'fields' => []];
    }

    return ['error' => null, 'fields' => $fields];
}

/**
 * The account's saved Apprise row, or null.
 */
function wallos_apprise_load(SQLite3 $db, $userId)
{
    $stmt = $db->prepare('SELECT * FROM apprise_notifications WHERE user_id = :userId');
    if ($stmt === false) {
        return null;
    }
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    return $result ? ($result->fetchArray(SQLITE3_ASSOC) ?: null) : null;
}

/**
 * The saved stateless URLs carry third-party secrets and are never sent to
 * the page. A save or test with the field left blank uses the saved ones;
 * `clear_urls` removes them.
 */
function wallos_apprise_with_stored_urls(array $input, ?array $stored)
{
    $posted = trim((string) ($input['urls'] ?? ''));
    if ($posted === '' && empty($input['clear_urls']) && $stored !== null) {
        $input['urls'] = (string) ($stored['urls'] ?? '');
    }

    return $input;
}
