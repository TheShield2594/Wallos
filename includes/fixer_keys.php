<?php
/*
  Exchange-rate API keys per provider (issue #44, migration 000076).

  fixer_keys holds one key per provider that needs one (0 fixer.io,
  1 apilayer.com), so switching providers and back does not mean entering a
  key again. The fixer row stays the active selection, and its api_key the
  active provider's key, which every exchange-rate reader uses.
*/

const WALLOS_FIXER_KEYED_PROVIDERS = [0, 1];

/**
 * The saved keys, provider => key.
 *
 * @return array<int, string>
 */
function wallos_fixer_saved_keys(SQLite3 $db, $userId): array
{
    $stmt = $db->prepare('SELECT provider, api_key FROM fixer_keys WHERE user_id = :userId');
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    $keys = [];
    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        if ((string) $row['api_key'] !== '') {
            $keys[(int) $row['provider']] = (string) $row['api_key'];
        }
    }

    return $keys;
}

/**
 * A key for display: never the key itself, only its last four characters.
 */
function wallos_fixer_key_hint(string $key): string
{
    return mb_strlen($key) > 8 ? '••••' . mb_substr($key, -4) : '••••';
}

/**
 * The key a save for $provider uses: the one posted, else the one saved for
 * that provider, else a key left on a frankfurter.dev row from before keys
 * were kept per provider (its provider is unknown, so validating it against
 * the chosen one decides). '' when there is none.
 */
function wallos_fixer_resolve_key(SQLite3 $db, $userId, int $provider, string $postedKey): string
{
    $postedKey = trim($postedKey);
    if ($postedKey !== '') {
        return $postedKey;
    }

    $saved = wallos_fixer_saved_keys($db, $userId);
    if (isset($saved[$provider])) {
        return $saved[$provider];
    }

    $stmt = $db->prepare('SELECT api_key, provider FROM fixer WHERE user_id = :userId');
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
    if ($row && (int) $row['provider'] === 2 && (string) $row['api_key'] !== '') {
        return (string) $row['api_key'];
    }

    return '';
}

/**
 * Saves a validated key for one provider.
 */
function wallos_fixer_store_key(SQLite3 $db, $userId, int $provider, string $key): bool
{
    if (!in_array($provider, WALLOS_FIXER_KEYED_PROVIDERS, true) || $key === '') {
        return false;
    }
    $stmt = $db->prepare('INSERT INTO fixer_keys (user_id, provider, api_key) VALUES (:userId, :provider, :key)
        ON CONFLICT (user_id, provider) DO UPDATE SET api_key = excluded.api_key');
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':provider', $provider, SQLITE3_INTEGER);
    $stmt->bindValue(':key', $key, SQLITE3_TEXT);

    return $stmt->execute() !== false;
}

/**
 * Removes every saved key of the account (the API's "clear" request).
 */
function wallos_fixer_clear_keys(SQLite3 $db, $userId): bool
{
    $stmt = $db->prepare('DELETE FROM fixer_keys WHERE user_id = :userId');
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);

    return $stmt->execute() !== false;
}

/**
 * Removes the key saved for one provider. When that provider is the active
 * one, its active row goes too, so the account has no rate provider
 * configured (as an empty key used to do before keys were kept per
 * provider).
 */
function wallos_fixer_remove_key(SQLite3 $db, $userId, int $provider): bool
{
    if (!in_array($provider, WALLOS_FIXER_KEYED_PROVIDERS, true) || @$db->exec('BEGIN IMMEDIATE') === false) {
        return false;
    }
    $ok = true;
    foreach ([
        'DELETE FROM fixer_keys WHERE user_id = :userId AND provider = :provider',
        'DELETE FROM fixer WHERE user_id = :userId AND provider = :provider',
    ] as $sql) {
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
        $stmt->bindValue(':provider', $provider, SQLITE3_INTEGER);
        if ($stmt->execute() === false) {
            $ok = false;
            break;
        }
    }
    if ($ok && $db->exec('COMMIT') !== false) {
        return true;
    }
    $db->exec('ROLLBACK');

    return false;
}
