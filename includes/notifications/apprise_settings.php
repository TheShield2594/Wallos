<?php
/*
  Validation of the Apprise settings form (issue #38).
*/

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
    } elseif ($fields['urls'] === '' && $needed) {
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
