<?php
/*
  Scoped API keys (issue #43): creating, listing and revoking them.

  A key is "tally_" followed by 40 hex characters. Only its sha256 is stored,
  with the first 12 characters as a prefix so people can tell their keys
  apart; the key itself is shown once, when it is created, and cannot be
  shown again. Keys migrated from the old user.api_key column (64 hex
  characters, no "tally_") keep working the same way.

  Scopes:
    read        the account's own data (the api get_ endpoints)
    write       changing the account's own data (the api set_ endpoints)
    admin:read  every account's subscriptions and the admin settings; only
                works while the key's owner is an admin, and only admins can
                create it
    ical        the iCalendar feed and nothing else (calendar subscriptions
                have to carry the key in the URL)

  admin:read includes read, and read includes ical.
*/

require_once __DIR__ . '/admin_roles.php';

const WALLOS_API_KEY_PREFIX = 'tally_';
const WALLOS_API_SCOPES = ['read', 'write', 'admin:read', 'ical'];

/**
 * @return string a new key, in full
 */
function wallos_api_generate_key()
{
    return WALLOS_API_KEY_PREFIX . bin2hex(random_bytes(20));
}

/**
 * @param string $key
 * @return string
 */
function wallos_api_hash_key($key)
{
    return hash('sha256', (string) $key);
}

/**
 * The part of a key that is stored and shown to tell it apart.
 *
 * @param string $key
 * @return string
 */
function wallos_api_key_display_prefix($key)
{
    $key = (string) $key;

    return strpos($key, WALLOS_API_KEY_PREFIX) === 0 ? substr($key, 0, 12) : substr($key, 0, 8);
}

/**
 * Scopes as a clean list in canonical order; unknown ones are dropped.
 *
 * @param string|string[] $scopes
 * @return string[]
 */
function wallos_api_normalize_scopes($scopes)
{
    if (!is_array($scopes)) {
        $scopes = preg_split('/[\s,]+/', (string) $scopes);
    }
    $scopes = array_map(fn($scope) => strtolower(trim((string) $scope)), $scopes);

    return array_values(array_intersect(WALLOS_API_SCOPES, $scopes));
}

/**
 * Whether a key with these scopes may do what needs $required.
 *
 * @param string|string[] $scopes
 * @param string          $required
 * @return bool
 */
function wallos_api_key_has_scope($scopes, $required)
{
    $scopes = wallos_api_normalize_scopes($scopes);

    if (in_array($required, $scopes, true)) {
        return true;
    }
    if ($required === 'read') {
        return in_array('admin:read', $scopes, true);
    }
    if ($required === 'ical') {
        return in_array('read', $scopes, true) || in_array('admin:read', $scopes, true);
    }

    return false;
}

/**
 * Creates a key for an account.
 *
 * Whether the caller may ask for admin:read (an admin who confirmed their
 * password) is checked by the endpoint; this refuses it for a non-admin
 * account all the same.
 *
 * @param SQLite3         $db
 * @param int             $userId
 * @param string          $name
 * @param string|string[] $scopes
 * @return array{success: bool, reason?: string, key?: string, id?: int, prefix?: string, scopes?: string[]}
 */
function wallos_api_create_key($db, $userId, $name, $scopes)
{
    $userId = (int) $userId;
    $name = trim((string) $name);
    $scopes = wallos_api_normalize_scopes($scopes);

    if ($name === '' || mb_strlen($name) > 100) {
        return ['success' => false, 'reason' => 'invalid_name'];
    }
    if ($scopes === []) {
        return ['success' => false, 'reason' => 'no_scopes'];
    }
    if (in_array('admin:read', $scopes, true) && !wallos_is_admin($db, $userId)) {
        return ['success' => false, 'reason' => 'not_admin'];
    }

    $key = wallos_api_generate_key();
    $stmt = $db->prepare('INSERT INTO api_keys (user_id, name, prefix, hash, scopes, created_at)
        VALUES (:user_id, :name, :prefix, :hash, :scopes, :created_at)');
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':name', $name, SQLITE3_TEXT);
    $stmt->bindValue(':prefix', wallos_api_key_display_prefix($key), SQLITE3_TEXT);
    $stmt->bindValue(':hash', wallos_api_hash_key($key), SQLITE3_TEXT);
    $stmt->bindValue(':scopes', implode(',', $scopes), SQLITE3_TEXT);
    $stmt->bindValue(':created_at', gmdate('Y-m-d H:i:s'), SQLITE3_TEXT);
    if ($stmt->execute() === false) {
        return ['success' => false, 'reason' => 'database'];
    }

    return [
        'success' => true,
        'key' => $key,
        'id' => (int) $db->lastInsertRowID(),
        'prefix' => wallos_api_key_display_prefix($key),
        'scopes' => $scopes,
    ];
}

/**
 * Keys of one account, or of every account when $userId is null (admin
 * view, with the owner's username). Never includes the hash.
 *
 * @param SQLite3  $db
 * @param int|null $userId
 * @param bool     $includeRevoked
 * @return array[]
 */
function wallos_api_list_keys($db, $userId = null, $includeRevoked = false)
{
    $sql = 'SELECT k.id, k.user_id, u.username, k.name, k.prefix, k.scopes, k.created_at, k.last_used_at, k.revoked_at
        FROM api_keys k LEFT JOIN user u ON u.id = k.user_id WHERE 1 = 1';
    if ($userId !== null) {
        $sql .= ' AND k.user_id = :user_id';
    }
    if (!$includeRevoked) {
        $sql .= ' AND k.revoked_at IS NULL';
    }
    $sql .= ' ORDER BY k.created_at DESC, k.id DESC';

    $stmt = $db->prepare($sql);
    if ($userId !== null) {
        $stmt->bindValue(':user_id', (int) $userId, SQLITE3_INTEGER);
    }
    $result = $stmt->execute();

    $keys = [];
    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $row['scopes'] = wallos_api_normalize_scopes($row['scopes']);
        $keys[] = $row;
    }

    return $keys;
}

/**
 * Revokes a key. With $userId set, only that account's key.
 *
 * @param SQLite3  $db
 * @param int      $keyId
 * @param int|null $userId null for an admin revoking any key
 * @return bool true when a key was revoked
 */
function wallos_api_revoke_key($db, $keyId, $userId = null)
{
    $sql = 'UPDATE api_keys SET revoked_at = :now WHERE id = :id AND revoked_at IS NULL';
    if ($userId !== null) {
        $sql .= ' AND user_id = :user_id';
    }
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':now', gmdate('Y-m-d H:i:s'), SQLITE3_TEXT);
    $stmt->bindValue(':id', (int) $keyId, SQLITE3_INTEGER);
    if ($userId !== null) {
        $stmt->bindValue(':user_id', (int) $userId, SQLITE3_INTEGER);
    }
    $stmt->execute();

    return $db->changes() > 0;
}

const WALLOS_API_MAX_KEYS_PER_USER = 50;
const WALLOS_API_OIDC_REAUTH_WINDOW = 600;

/**
 * Confirms it is really the account holder before an admin:read key is made.
 *
 * Accepted, in order:
 *  - the account password;
 *  - a current TOTP code, when two-factor authentication is on;
 *  - a sign-in through the identity provider within the last ten minutes,
 *    for OIDC accounts whose local password nobody knows (they get a random
 *    one when the provider creates them). Signing out and back in through
 *    the provider counts as re-entering the credentials.
 *
 * @param SQLite3  $db
 * @param int      $userId
 * @param string   $password
 * @param string   $totpCode
 * @param array    $session  $_SESSION
 * @param int|null $now
 * @return bool
 */
function wallos_api_reauthenticate($db, $userId, $password, $totpCode, array $session, $now = null)
{
    $now = $now ?? time();

    $stmt = $db->prepare('SELECT password, totp_enabled FROM user WHERE id = :id');
    $stmt->bindValue(':id', (int) $userId, SQLITE3_INTEGER);
    $user = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if ($user === false) {
        return false;
    }

    if (is_string($password) && $password !== '' && password_verify($password, (string) $user['password'])) {
        return true;
    }

    if ((int) $user['totp_enabled'] === 1 && is_string($totpCode) && preg_match('/^\d{6}$/', trim($totpCode))) {
        if (wallos_api_verify_totp($db, $userId, trim($totpCode))) {
            return true;
        }
    }

    $oidcLoginAt = (int) ($session['oidc_login_at'] ?? 0);
    if (!empty($session['from_oidc']) && $oidcLoginAt > 0 && $now - $oidcLoginAt <= WALLOS_API_OIDC_REAUTH_WINDOW) {
        return true;
    }

    return false;
}

/**
 * @param SQLite3 $db
 * @param int     $userId
 * @param string  $code
 * @return bool
 */
function wallos_api_verify_totp($db, $userId, $code)
{
    $stmt = $db->prepare('SELECT totp_secret FROM totp WHERE user_id = :id');
    $stmt->bindValue(':id', (int) $userId, SQLITE3_INTEGER);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if ($row === false || empty($row['totp_secret'])) {
        return false;
    }

    $libs = __DIR__ . '/../libs/';
    foreach ([
        'OTPHP/FactoryInterface.php', 'OTPHP/Factory.php', 'OTPHP/ParameterTrait.php', 'OTPHP/OTPInterface.php',
        'OTPHP/OTP.php', 'OTPHP/TOTPInterface.php', 'OTPHP/TOTP.php', 'Psr/Clock/ClockInterface.php',
        'OTPHP/InternalClock.php', 'constant_time_encoding/Binary.php', 'constant_time_encoding/EncoderInterface.php',
        'constant_time_encoding/Base32.php',
    ] as $file) {
        require_once $libs . $file;
    }
    if (!function_exists('trigger_deprecation')) {
        function trigger_deprecation($package, $version, $message, ...$args)
        {
            trigger_error(sprintf($message, ...$args), E_USER_DEPRECATED);
        }
    }

    $totp = OTPHP\TOTP::createFromSecret($row['totp_secret'], new OTPHP\InternalClock());
    $totp->setPeriod(30);

    return $totp->verify($code, null, 15);
}

/**
 * @param SQLite3 $db
 * @param int     $userId
 * @return int
 */
function wallos_api_active_key_count($db, $userId)
{
    $stmt = $db->prepare('SELECT COUNT(*) AS n FROM api_keys WHERE user_id = :id AND revoked_at IS NULL');
    $stmt->bindValue(':id', (int) $userId, SQLITE3_INTEGER);

    return (int) $stmt->execute()->fetchArray(SQLITE3_ASSOC)['n'];
}
