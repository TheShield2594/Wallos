<?php
/*
  Scoped API keys (issue #43).

  api_keys            one row per key. Only sha256(key) is stored, plus a
                      short prefix to tell keys apart in the UI; the key itself
                      is shown once, when it is created. scopes is a
                      comma-separated list: read, write, admin:read, ical.
                      revoked_at set means the key no longer works.
  api_auth_failures   failed key lookups per client address, for the rate
                      limit in includes/api_auth.php.

  Every existing user.api_key becomes a "Legacy key" with read and write, so
  scripts and calendar subscriptions keep working. user.api_key itself is kept
  for one release and no longer used to authenticate.

  Safe to run twice: a key already migrated (same hash) is skipped.
*/

$migrate = function ($sql) use ($db) {
    if ($db->exec($sql) === false) {
        throw new RuntimeException('Migration 000080 failed: ' . $db->lastErrorMsg());
    }
};

$migrate("CREATE TABLE IF NOT EXISTS api_keys (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    prefix TEXT NOT NULL,
    hash TEXT NOT NULL,
    scopes TEXT NOT NULL,
    created_at TEXT NOT NULL,
    last_used_at TEXT DEFAULT NULL,
    revoked_at TEXT DEFAULT NULL
)");
$migrate("CREATE UNIQUE INDEX IF NOT EXISTS idx_api_keys_hash ON api_keys (hash)");
$migrate("CREATE INDEX IF NOT EXISTS idx_api_keys_user ON api_keys (user_id)");

$migrate("CREATE TABLE IF NOT EXISTS api_auth_failures (
    client TEXT PRIMARY KEY,
    failures INTEGER NOT NULL DEFAULT 0,
    window_started INTEGER NOT NULL
)");

$legacyKeys = [];
$result = $db->query("SELECT id, api_key FROM user WHERE api_key IS NOT NULL AND api_key != ''");
if ($result === false) {
    throw new RuntimeException('Migration 000080 failed: ' . $db->lastErrorMsg());
}
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $legacyKeys[] = $row;
}

$insert = $db->prepare("INSERT OR IGNORE INTO api_keys (user_id, name, prefix, hash, scopes, created_at)
    VALUES (:user_id, 'Legacy key', :prefix, :hash, 'read,write', :created_at)");
if ($insert === false) {
    throw new RuntimeException('Migration 000080 failed: ' . $db->lastErrorMsg());
}
foreach ($legacyKeys as $row) {
    $key = (string) $row['api_key'];
    $insert->bindValue(':user_id', (int) $row['id'], SQLITE3_INTEGER);
    $insert->bindValue(':prefix', substr($key, 0, 8), SQLITE3_TEXT);
    $insert->bindValue(':hash', hash('sha256', $key), SQLITE3_TEXT);
    $insert->bindValue(':created_at', gmdate('Y-m-d H:i:s'), SQLITE3_TEXT);
    if ($insert->execute() === false) {
        throw new RuntimeException('Migration 000080 failed: ' . $db->lastErrorMsg());
    }
    $insert->reset();
}
