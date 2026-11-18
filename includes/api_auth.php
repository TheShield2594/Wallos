<?php
/*
  API authentication (issue #43), used by every api/* endpoint.

  The key can be sent as
    Authorization: Bearer <key>   preferred
    X-API-Key: <key>
    ?api_key=<key> / apiKey       deprecated (it ends up in server and proxy
                                  logs); answered with a Deprecation header.
                                  Calendar feeds are the exception: calendar
                                  apps can only send the key in the URL.

  A key is looked up by its sha256, must not be revoked, and must carry the
  scope the endpoint needs (see includes/api_keys.php). Admin endpoints also
  need the key's owner to be an admin at the time of the call, so demoting
  someone disables their admin:read keys without revoking them.

  Failed lookups are counted per client address; more than
  WALLOS_API_MAX_FAILURES within WALLOS_API_FAILURE_WINDOW seconds and the
  address is refused (429) until the window has passed, valid key or not.
*/

require_once __DIR__ . '/api_keys.php';

const WALLOS_API_MAX_FAILURES = 20;
const WALLOS_API_FAILURE_WINDOW = 900;

/**
 * Finds the key in a request.
 *
 * @param array      $server  $_SERVER
 * @param array      $request $_REQUEST (GET and POST parameters)
 * @param array|null $payload a decoded JSON body, for endpoints that take one
 * @return array{key: string|null, source: string|null} source is 'bearer',
 *         'header' or 'query'
 */
function wallos_api_extract_key(array $server, array $request, $payload = null)
{
    $authorization = $server['HTTP_AUTHORIZATION'] ?? ($server['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (is_string($authorization) && preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $authorization, $match)) {
        return ['key' => $match[1], 'source' => 'bearer'];
    }

    $header = $server['HTTP_X_API_KEY'] ?? '';
    if (is_string($header) && trim($header) !== '') {
        return ['key' => trim($header), 'source' => 'header'];
    }

    foreach ([$request, is_array($payload) ? $payload : []] as $source) {
        foreach (['api_key', 'apiKey'] as $name) {
            if (isset($source[$name]) && is_string($source[$name]) && trim($source[$name]) !== '') {
                return ['key' => trim($source[$name]), 'source' => 'query'];
            }
        }
    }

    return ['key' => null, 'source' => null];
}

/**
 * The key row and its owner, or null for an unknown or revoked key.
 *
 * @param SQLite3 $db
 * @param string  $key
 * @return array|null ['key' => api_keys row (no hash), 'user' => user row]
 */
function wallos_api_lookup_key($db, $key)
{
    if (!is_string($key) || $key === '' || strlen($key) > 256) {
        return null;
    }

    $stmt = $db->prepare('SELECT id, user_id, name, prefix, scopes, created_at, last_used_at, revoked_at
        FROM api_keys WHERE hash = :hash AND revoked_at IS NULL');
    $stmt->bindValue(':hash', wallos_api_hash_key($key), SQLITE3_TEXT);
    $keyRow = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if ($keyRow === false) {
        return null;
    }

    $stmt = $db->prepare('SELECT * FROM user WHERE id = :id');
    $stmt->bindValue(':id', (int) $keyRow['user_id'], SQLITE3_INTEGER);
    $user = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if ($user === false) {
        return null;
    }

    return ['key' => $keyRow, 'user' => $user];
}

/**
 * Decides whether a key may make a call. Has no side effects.
 *
 * @param SQLite3     $db
 * @param string|null $key
 * @param string      $requiredScope read, write, admin:read or ical
 * @param bool        $requireAdmin  the owner must be an admin now
 * @return array{ok: bool, status: int, title: string, message: string, user?: array, key?: array}
 */
function wallos_api_authorize($db, $key, $requiredScope, $requireAdmin = false)
{
    if ($key === null || $key === '') {
        return ['ok' => false, 'status' => 200, 'title' => 'Missing parameters', 'message' => 'An API key is required.', 'failure' => false];
    }

    $found = wallos_api_lookup_key($db, $key);
    if ($found === null) {
        return ['ok' => false, 'status' => 200, 'title' => 'Invalid API key', 'message' => 'The API key is unknown or revoked.', 'failure' => true];
    }

    if (!wallos_api_key_has_scope($found['key']['scopes'], $requiredScope)) {
        return ['ok' => false, 'status' => 403, 'title' => 'Insufficient scope',
            'message' => 'This API key does not have the "' . $requiredScope . '" scope.', 'failure' => false];
    }

    $needsAdmin = $requireAdmin || $requiredScope === 'admin:read';
    if ($needsAdmin && !wallos_is_admin($db, $found['user']['id'])) {
        return ['ok' => false, 'status' => 403, 'title' => 'Denied. Not admin user',
            'message' => 'The owner of this API key is not an admin.', 'failure' => false];
    }

    return ['ok' => true, 'status' => 200, 'title' => 'OK', 'message' => '', 'user' => $found['user'], 'key' => $found['key'], 'failure' => false];
}

/**
 * @param SQLite3 $db
 * @param int     $keyId
 */
function wallos_api_touch_key($db, $keyId)
{
    // At most one write a minute per key: a polling integration should not
    // turn every read into a write.
    $stmt = $db->prepare("UPDATE api_keys SET last_used_at = :now WHERE id = :id
        AND (last_used_at IS NULL OR last_used_at < :threshold)");
    $stmt->bindValue(':now', gmdate('Y-m-d H:i:s'), SQLITE3_TEXT);
    $stmt->bindValue(':threshold', gmdate('Y-m-d H:i:s', time() - 60), SQLITE3_TEXT);
    $stmt->bindValue(':id', (int) $keyId, SQLITE3_INTEGER);
    @$stmt->execute();
}

/**
 * @param SQLite3 $db
 * @param string  $client
 * @param int|null $now
 * @return bool
 */
function wallos_api_is_rate_limited($db, $client, $now = null)
{
    $now = $now ?? time();
    $stmt = @$db->prepare('SELECT failures, window_started FROM api_auth_failures WHERE client = :client');
    if ($stmt === false) {
        return false;
    }
    $stmt->bindValue(':client', (string) $client, SQLITE3_TEXT);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if ($row === false || (int) $row['window_started'] + WALLOS_API_FAILURE_WINDOW <= $now) {
        return false;
    }

    return (int) $row['failures'] >= WALLOS_API_MAX_FAILURES;
}

/**
 * @param SQLite3  $db
 * @param string   $client
 * @param int|null $now
 */
function wallos_api_record_failure($db, $client, $now = null)
{
    $now = $now ?? time();
    $windowStart = $now - WALLOS_API_FAILURE_WINDOW;

    $stmt = @$db->prepare('INSERT INTO api_auth_failures (client, failures, window_started) VALUES (:client, 1, :now)
        ON CONFLICT(client) DO UPDATE SET
            failures = CASE WHEN window_started <= :window_start THEN 1 ELSE failures + 1 END,
            window_started = CASE WHEN window_started <= :window_start THEN :now ELSE window_started END');
    if ($stmt === false) {
        return;
    }
    $stmt->bindValue(':client', (string) $client, SQLITE3_TEXT);
    $stmt->bindValue(':now', $now, SQLITE3_INTEGER);
    $stmt->bindValue(':window_start', $windowStart, SQLITE3_INTEGER);
    @$stmt->execute();

    // Old rows are of no use; keep the table small.
    if (random_int(1, 50) === 1) {
        $cleanup = $db->prepare('DELETE FROM api_auth_failures WHERE window_started <= :window_start');
        $cleanup->bindValue(':window_start', $windowStart, SQLITE3_INTEGER);
        @$cleanup->execute();
    }
}

/**
 * Authenticates the current request or answers it with an error and exits.
 *
 * @param SQLite3 $db
 * @param string  $requiredScope read, write, admin:read or ical
 * @param array   $options requireAdmin (bool), payload (decoded JSON body),
 *                         queryKeyDeprecated (bool, default true)
 * @return array the key owner's user row; the key row is in $GLOBALS['wallos_api_key']
 */
function wallos_api_require($db, $requiredScope, array $options = [])
{
    $client = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

    if (wallos_api_is_rate_limited($db, $client)) {
        wallos_api_fail($db, 429, 'Too many requests', 'Too many failed API key attempts. Try again later.');
    }

    $extracted = wallos_api_extract_key($_SERVER, $_REQUEST, $options['payload'] ?? null);
    $decision = wallos_api_authorize($db, $extracted['key'], $requiredScope, !empty($options['requireAdmin']));

    if (!$decision['ok']) {
        if ($decision['failure']) {
            wallos_api_record_failure($db, $client);
        }
        wallos_api_fail($db, $decision['status'], $decision['title'], $decision['message']);
    }

    if ($extracted['source'] === 'query' && ($options['queryKeyDeprecated'] ?? true) && !headers_sent()) {
        header('Deprecation: true');
        header('Warning: 299 - "Passing the API key as a query parameter is deprecated; send it as Authorization: Bearer <key>."');
    }

    wallos_api_touch_key($db, $decision['key']['id']);
    $GLOBALS['wallos_api_key'] = $decision['key'];

    return $decision['user'];
}

/**
 * @return never
 */
function wallos_api_fail($db, $status, $title, $message)
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
    }
    echo json_encode([
        'success' => false,
        'title' => $title,
        'message' => $message,
    ]);
    $db->close();
    exit;
}
