<?php
/*
  Exchange-rate API keys per provider (issue #44).

  fixer_keys   one saved key per (user, provider): 0 fixer.io, 1 apilayer.com.
               frankfurter.dev (2) needs no key. Switching providers no longer
               overwrites the key of the other one.

  fixer keeps the active selection: provider, and api_key holding the active
  provider's key, which is what the exchange-rate readers use.

  Existing keys are copied for the provider they were saved with. A key left
  on a frankfurter.dev row belonged to fixer.io or apilayer, which the row
  does not say: it stays in fixer.api_key, and the next save for either
  provider with an empty key field tries it (see includes/fixer_keys.php).

  Safe to run twice.
*/

$migrate = function ($sql) use ($db) {
    if ($db->exec($sql) === false) {
        throw new RuntimeException('Migration 000076 failed: ' . $db->lastErrorMsg());
    }
};

$migrate("CREATE TABLE IF NOT EXISTS fixer_keys (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    provider INTEGER NOT NULL,
    api_key TEXT NOT NULL DEFAULT '',
    UNIQUE (user_id, provider)
)");

$migrate("INSERT OR IGNORE INTO fixer_keys (user_id, provider, api_key)
    SELECT user_id, provider, api_key FROM fixer
    WHERE provider IN (0, 1) AND api_key IS NOT NULL AND api_key != ''");
