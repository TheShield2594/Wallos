<?php
/*
  Admin role (issue #45).

  An account is an admin when user.is_admin = 1. Before, "admin" meant
  "user id 1"; migration 000079 makes that account the first admin.

  Instance-wide settings still live in the admin and oauth_settings rows with
  id 1. Those rows are not tied to user 1, so deleting or demoting that
  account leaves them where they are; only who may change them is decided
  here.

  The last admin can never be demoted or deleted: neither by another admin,
  nor by an OIDC login whose role claim no longer lists them. Otherwise one
  misconfigured group mapping would lock everyone out of the admin page.
*/

/**
 * @param SQLite3 $db
 * @param int     $userId
 * @return bool
 */
function wallos_is_admin($db, $userId)
{
    $userId = (int) $userId;
    if ($userId <= 0) {
        return false;
    }

    $stmt = @$db->prepare('SELECT is_admin FROM user WHERE id = :id');
    if ($stmt === false) {
        // Database from before migration 000079: fall back to the old rule
        // so an instance being upgraded can still reach endpoints/db/migrate.php.
        return $userId === 1;
    }
    $stmt->bindValue(':id', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;

    return $row !== false && (int) $row['is_admin'] === 1;
}

if (!function_exists('is_admin')) {
    /**
     * Short alias of wallos_is_admin(), as named in issue #45.
     */
    function is_admin($db, $userId)
    {
        return wallos_is_admin($db, $userId);
    }
}

/**
 * @param SQLite3 $db
 * @return int
 */
function wallos_admin_count($db)
{
    return (int) $db->querySingle('SELECT COUNT(*) FROM user WHERE is_admin = 1');
}

/**
 * Admins that can sign in through the identity provider (their account is
 * linked to an OIDC subject). Turning password login off is only safe when
 * there is at least one.
 *
 * @param SQLite3 $db
 * @return int
 */
function wallos_oidc_admin_count($db)
{
    return (int) $db->querySingle("SELECT COUNT(*) FROM user WHERE is_admin = 1 AND oidc_sub IS NOT NULL AND oidc_sub != ''");
}

/**
 * The account instance-wide fallbacks act as (the "disable login" single-user
 * mode, for one): the oldest admin.
 *
 * @param SQLite3 $db
 * @return int 0 when there is none
 */
function wallos_primary_admin_id($db)
{
    return (int) $db->querySingle('SELECT id FROM user WHERE is_admin = 1 ORDER BY id ASC LIMIT 1');
}

/**
 * Promotes or demotes an account.
 *
 * @param SQLite3 $db
 * @param int     $userId
 * @param bool    $isAdmin
 * @return array{success: bool, changed: bool, reason: string|null}
 *         reason is 'not_found' or 'last_admin' on failure
 */
function wallos_set_user_admin($db, $userId, $isAdmin)
{
    $userId = (int) $userId;
    $isAdmin = (bool) $isAdmin;

    $stmt = $db->prepare('SELECT is_admin FROM user WHERE id = :id');
    $stmt->bindValue(':id', $userId, SQLITE3_INTEGER);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if ($row === false) {
        return ['success' => false, 'changed' => false, 'reason' => 'not_found'];
    }

    $current = (int) $row['is_admin'] === 1;
    if ($current === $isAdmin) {
        return ['success' => true, 'changed' => false, 'reason' => null];
    }

    if (!$isAdmin) {
        // The check and the write are one statement, so two concurrent
        // demotions cannot both see "another admin is left".
        $stmt = $db->prepare('UPDATE user SET is_admin = 0 WHERE id = :id
            AND (SELECT COUNT(*) FROM user WHERE is_admin = 1 AND id != :id) > 0');
    } else {
        $stmt = $db->prepare('UPDATE user SET is_admin = 1 WHERE id = :id');
    }
    $stmt->bindValue(':id', $userId, SQLITE3_INTEGER);
    $stmt->execute();

    if ($db->changes() === 0) {
        return ['success' => false, 'changed' => false, 'reason' => 'last_admin'];
    }

    return ['success' => true, 'changed' => true, 'reason' => null];
}

/**
 * Whether an account may be deleted without leaving the instance without an
 * admin.
 *
 * @param SQLite3 $db
 * @param int     $userId
 * @return bool
 */
function wallos_can_delete_user($db, $userId)
{
    if (!wallos_is_admin($db, $userId)) {
        return true;
    }

    return wallos_admin_count($db) > 1;
}

/**
 * Values of a role/group claim as a flat list of strings.
 *
 * Providers send groups as a JSON array (Authentik, Keycloak, Authelia), and
 * some as one string with the values separated by spaces or commas
 * (scope-style claims, or a single group). Nested arrays are flattened;
 * anything that is not a string or number is ignored.
 *
 * @param mixed $value
 * @return string[]
 */
function wallos_oidc_parse_claim_values($value)
{
    $values = [];

    if (is_array($value)) {
        foreach ($value as $item) {
            if (is_array($item)) {
                $values = array_merge($values, wallos_oidc_parse_claim_values($item));
            } elseif (is_string($item) || is_int($item) || is_float($item)) {
                $item = trim((string) $item);
                if ($item !== '') {
                    $values[] = $item;
                }
            }
        }
    } elseif (is_string($value) || is_int($value) || is_float($value)) {
        foreach (preg_split('/[\s,]+/', (string) $value) as $item) {
            if ($item !== '') {
                $values[] = $item;
            }
        }
    }

    return array_values(array_unique($values));
}

/**
 * The configured admin values ("tally-admins, admins") as a list.
 *
 * @param string $adminValues
 * @return string[]
 */
function wallos_oidc_parse_admin_values($adminValues)
{
    $values = [];
    foreach (explode(',', (string) $adminValues) as $value) {
        $value = trim($value);
        if ($value !== '') {
            $values[] = $value;
        }
    }

    return array_values(array_unique($values));
}

/**
 * Looks a claim up by name. A name that is not a top-level key is tried as a
 * dotted path, for claims nested in an object such as Keycloak's
 * "realm_access.roles".
 *
 * @param array  $claims
 * @param string $name
 * @return mixed|null null when absent
 */
function wallos_oidc_claim_lookup($claims, $name)
{
    if (!is_array($claims) || $name === '') {
        return null;
    }

    if (array_key_exists($name, $claims)) {
        return $claims[$name];
    }

    if (strpos($name, '.') === false) {
        return null;
    }

    $current = $claims;
    foreach (explode('.', $name) as $part) {
        if (!is_array($current) || !array_key_exists($part, $current)) {
            return null;
        }
        $current = $current[$part];
    }

    return $current;
}

/**
 * Claims of an ID token, without verifying its signature.
 *
 * Only used on an ID token Tally received itself, directly from the token
 * endpoint over TLS in the authorization code flow; OpenID Connect Core
 * 3.1.3.7 allows TLS server validation in place of the signature check there.
 * Never use it on a token that came from the browser.
 *
 * @param string|null $idToken
 * @return array
 */
function wallos_oidc_decode_id_token_claims($idToken)
{
    if (!is_string($idToken) || substr_count($idToken, '.') !== 2) {
        return [];
    }

    $payload = explode('.', $idToken)[1];
    $payload = strtr($payload, '-_', '+/');
    $padding = strlen($payload) % 4;
    if ($padding > 0) {
        $payload .= str_repeat('=', 4 - $padding);
    }

    $json = base64_decode($payload, true);
    if ($json === false) {
        return [];
    }

    $claims = json_decode($json, true);

    return is_array($claims) ? $claims : [];
}

/**
 * Whether the claims make the account an admin.
 *
 * @param array  $idTokenClaims
 * @param array  $userInfo
 * @param string $claimName
 * @param string $adminValues comma-separated
 * @return bool|null null when role mapping is not configured
 */
function wallos_oidc_resolve_admin($idTokenClaims, $userInfo, $claimName, $adminValues)
{
    $claimName = trim((string) $claimName);
    $wanted = wallos_oidc_parse_admin_values($adminValues);
    if ($claimName === '' || $wanted === []) {
        return null;
    }

    // Both sources count: some providers put groups only in the ID token,
    // others only in userinfo.
    $values = array_merge(
        wallos_oidc_parse_claim_values(wallos_oidc_claim_lookup($idTokenClaims, $claimName)),
        wallos_oidc_parse_claim_values(wallos_oidc_claim_lookup($userInfo, $claimName))
    );

    return array_intersect($wanted, $values) !== [];
}

/**
 * Brings is_admin in line with the identity provider at login.
 *
 * @param SQLite3   $db
 * @param int       $userId
 * @param bool|null $shouldBeAdmin from wallos_oidc_resolve_admin()
 * @return string 'not_configured', 'unchanged', 'promoted', 'demoted' or
 *                'kept_last_admin'
 */
function wallos_oidc_sync_admin($db, $userId, $shouldBeAdmin)
{
    if ($shouldBeAdmin === null) {
        return 'not_configured';
    }

    $result = wallos_set_user_admin($db, $userId, $shouldBeAdmin);
    if (!$result['success']) {
        if ($result['reason'] === 'last_admin') {
            error_log('[Tally OIDC] Not removing admin rights from user ' . (int) $userId
                . ': the role claim no longer lists them, but they are the last admin. Promote another admin first.');
            return 'kept_last_admin';
        }

        return 'unchanged';
    }

    if (!$result['changed']) {
        return 'unchanged';
    }

    return $shouldBeAdmin ? 'promoted' : 'demoted';
}

/**
 * Whether TALLY_FORCE_PASSWORD_LOGIN asks for password login to stay on
 * whatever the OIDC settings say (the way back in when the identity provider
 * is unreachable).
 *
 * @return bool
 */
function wallos_force_password_login()
{
    $value = getenv('TALLY_FORCE_PASSWORD_LOGIN');
    if ($value === false) {
        $value = $_ENV['TALLY_FORCE_PASSWORD_LOGIN'] ?? ($_SERVER['TALLY_FORCE_PASSWORD_LOGIN'] ?? null);
    }
    if ($value === null) {
        return false;
    }

    return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
}
