<?php
/*
  Scoped API keys (issue #43): hashed storage, scopes, revocation, the legacy
  key migration, how a request carries its key, and the failed-lookup limit.
*/

require_once WALLOS_ROOT . '/includes/api_auth.php';

/**
 * Alice (admin) and Bob, each with a key.
 *
 * @return array{0: SQLite3, 1: string, 2: string} db, Alice's key, Bob's key
 */
function api_keys_fixture($aliceScopes = 'read,write,admin:read', $bobScopes = 'read')
{
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    wallos_test_create_user($db, 2, 'bob');
    $db->exec('UPDATE user SET is_admin = 1 WHERE id = 1');

    $alice = wallos_api_create_key($db, 1, 'alice key', $aliceScopes);
    $bob = wallos_api_create_key($db, 2, 'bob key', $bobScopes);
    assert_true($alice['success'] && $bob['success'], 'fixture keys are created');

    return [$db, $alice['key'], $bob['key']];
}

wallos_test('keys are generated with a prefix and stored only as a hash', function () {
    [$db, $aliceKey] = api_keys_fixture();

    assert_true((bool) preg_match('/^tally_[0-9a-f]{40}$/', $aliceKey), 'the key format');
    assert_same(hash('sha256', $aliceKey), wallos_api_hash_key($aliceKey), 'sha256 of the key');
    assert_same(substr($aliceKey, 0, 12), wallos_api_key_display_prefix($aliceKey), 'a 12 character display prefix');
    assert_same('abcdef01', wallos_api_key_display_prefix(str_repeat('abcdef01', 8)), 'legacy keys show 8 characters');

    $row = $db->querySingle("SELECT * FROM api_keys WHERE user_id = 1", true);
    assert_same(wallos_api_hash_key($aliceKey), $row['hash'], 'the hash is stored');
    assert_same(0, (int) $db->querySingle("SELECT COUNT(*) FROM api_keys WHERE hash = '" . SQLite3::escapeString($aliceKey) . "' OR prefix = '" . SQLite3::escapeString($aliceKey) . "'"), 'the key itself is not');
    assert_same('read,write,admin:read', $row['scopes'], 'scopes in canonical order');

    $listed = wallos_api_list_keys($db, 1);
    assert_same(1, count($listed), 'one key listed');
    assert_true(!array_key_exists('hash', $listed[0]), 'listing never includes the hash');
    assert_same(2, count(wallos_api_list_keys($db, null)), 'the admin view lists every account');
    assert_same('bob', wallos_api_list_keys($db, 2)[0]['username'], 'with the owner');
    $db->close();
});

wallos_test('scopes are normalised and imply what they should', function () {
    assert_same(['read', 'write'], wallos_api_normalize_scopes('write, READ bogus'), 'unknown scopes dropped, canonical order');
    assert_same(['ical'], wallos_api_normalize_scopes(['ical', 'ical']), 'a list');
    assert_true(wallos_api_key_has_scope('read', 'read'), 'read');
    assert_true(!wallos_api_key_has_scope('read', 'write'), 'read is not write');
    assert_true(!wallos_api_key_has_scope('write', 'read'), 'write is not read');
    assert_true(wallos_api_key_has_scope('admin:read', 'read'), 'admin:read includes read');
    assert_true(!wallos_api_key_has_scope('read,write', 'admin:read'), 'read,write is not admin:read');
    assert_true(wallos_api_key_has_scope('read', 'ical'), 'read includes the calendar feed');
    assert_true(wallos_api_key_has_scope('ical', 'ical'), 'ical');
    assert_true(!wallos_api_key_has_scope('ical', 'read'), 'an ical key reads nothing else');
});

wallos_test('the key is read from Bearer, X-API-Key, then the deprecated parameter', function () {
    assert_same(['key' => 'k1', 'source' => 'bearer'], wallos_api_extract_key(['HTTP_AUTHORIZATION' => 'Bearer k1'], ['api_key' => 'k3']), 'Bearer wins');
    assert_same(['key' => 'k1', 'source' => 'bearer'], wallos_api_extract_key(['REDIRECT_HTTP_AUTHORIZATION' => 'bearer   k1 '], []), 'case and spacing, redirected header');
    assert_same(['key' => 'k2', 'source' => 'header'], wallos_api_extract_key(['HTTP_X_API_KEY' => ' k2 '], ['api_key' => 'k3']), 'then X-API-Key');
    assert_same(['key' => 'k2', 'source' => 'header'], wallos_api_extract_key(['HTTP_AUTHORIZATION' => 'Basic Zm9v', 'HTTP_X_API_KEY' => 'k2'], []), 'Basic auth is not a key');
    assert_same(['key' => 'k3', 'source' => 'query'], wallos_api_extract_key([], ['api_key' => 'k3']), 'then api_key');
    assert_same(['key' => 'k4', 'source' => 'query'], wallos_api_extract_key([], ['apiKey' => 'k4']), 'or apiKey');
    assert_same(['key' => 'k5', 'source' => 'query'], wallos_api_extract_key([], [], ['api_key' => 'k5']), 'or a JSON body');
    assert_same(['key' => null, 'source' => null], wallos_api_extract_key([], ['api_key' => ['x']]), 'an array is not a key');
    assert_same(['key' => null, 'source' => null], wallos_api_extract_key(['HTTP_AUTHORIZATION' => ''], []), 'nothing');
});

wallos_test('scopes are enforced per call', function () {
    [$db, $aliceKey, $bobKey] = api_keys_fixture('read,write,admin:read', 'read');

    $result = wallos_api_authorize($db, $bobKey, 'read');
    assert_true($result['ok'], 'a read key reads');
    assert_same(2, (int) $result['user']['id'], 'as its owner');

    $result = wallos_api_authorize($db, $bobKey, 'write');
    assert_same(false, $result['ok'], 'a read key cannot write');
    assert_same(403, $result['status'], 'forbidden');
    assert_same(false, $result['failure'], 'and that is not counted as a failed lookup');

    assert_true(wallos_api_authorize($db, $bobKey, 'ical')['ok'], 'a read key opens the calendar feed');
    assert_same(false, wallos_api_authorize($db, $bobKey, 'admin:read')['ok'], 'a read key is not admin:read');
    assert_same(false, wallos_api_authorize($db, $bobKey, 'write', true)['ok'], 'admin endpoints need an admin owner');

    assert_true(wallos_api_authorize($db, $aliceKey, 'admin:read')['ok'], 'the admin key reads everything');
    assert_true(wallos_api_authorize($db, $aliceKey, 'write', true)['ok'], 'and may write admin settings');

    $missing = wallos_api_authorize($db, null, 'read');
    assert_same('Missing parameters', $missing['title'], 'no key');
    $unknown = wallos_api_authorize($db, 'tally_' . str_repeat('0', 40), 'read');
    assert_same('Invalid API key', $unknown['title'], 'an unknown key');
    assert_same(true, $unknown['failure'], 'counts as a failed lookup');
    $db->close();
});

wallos_test('admin:read stops working when its owner stops being an admin', function () {
    [$db, $aliceKey] = api_keys_fixture();
    $db->exec('UPDATE user SET is_admin = 1 WHERE id = 2');
    assert_true(wallos_set_user_admin($db, 1, false)['success'], 'alice is demoted');

    assert_same(false, wallos_api_authorize($db, $aliceKey, 'admin:read')['ok'], 'her admin:read key is refused');
    assert_true(wallos_api_authorize($db, $aliceKey, 'read')['ok'], 'but still reads her own data');
    $db->close();
});

wallos_test('only admins get admin:read keys', function () {
    [$db] = api_keys_fixture();
    $result = wallos_api_create_key($db, 2, 'sneaky', ['read', 'admin:read']);
    assert_same(false, $result['success'], 'bob cannot');
    assert_same('not_admin', $result['reason'], 'because he is not an admin');
    assert_same('no_scopes', wallos_api_create_key($db, 2, 'empty', ['nonsense'])['reason'], 'a key needs a scope');
    assert_same('invalid_name', wallos_api_create_key($db, 2, '  ', ['read'])['reason'], 'and a name');
    $db->close();
});

wallos_test('revoked keys are rejected', function () {
    [$db, $aliceKey, $bobKey] = api_keys_fixture();
    $bobKeyId = (int) $db->querySingle('SELECT id FROM api_keys WHERE user_id = 2');

    assert_same(false, wallos_api_revoke_key($db, $bobKeyId, 1), 'alice cannot revoke bob\'s key as a user');
    assert_true(wallos_api_authorize($db, $bobKey, 'read')['ok'], 'so it still works');
    assert_true(wallos_api_revoke_key($db, $bobKeyId, 2), 'bob revokes it');
    assert_same(false, wallos_api_revoke_key($db, $bobKeyId, 2), 'only once');

    $result = wallos_api_authorize($db, $bobKey, 'read');
    assert_same(false, $result['ok'], 'a revoked key is refused');
    assert_same('Invalid API key', $result['title'], 'like an unknown one');
    assert_same(0, count(wallos_api_list_keys($db, 2)), 'and is no longer listed');

    $aliceKeyId = (int) $db->querySingle('SELECT id FROM api_keys WHERE user_id = 1');
    assert_true(wallos_api_revoke_key($db, $aliceKeyId, null), 'an admin revokes any key');
    assert_same(false, wallos_api_authorize($db, $aliceKey, 'read')['ok'], 'that one is refused too');
    $db->close();
});

wallos_test('using a key records when it was last used', function () {
    [$db, , $bobKey] = api_keys_fixture();
    $keyId = (int) $db->querySingle('SELECT id FROM api_keys WHERE user_id = 2');
    assert_same(null, $db->querySingle('SELECT last_used_at FROM api_keys WHERE id = ' . $keyId), 'never used');
    wallos_api_touch_key($db, $keyId);
    assert_true($db->querySingle('SELECT last_used_at FROM api_keys WHERE id = ' . $keyId) !== null, 'now it has been');
    $db->close();
});

wallos_test('existing user.api_key values become hashed legacy keys', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    wallos_test_create_user($db, 2, 'bob');
    wallos_test_create_user($db, 3, 'carol');
    $legacy = str_repeat('ab12', 16);
    $db->exec("UPDATE user SET api_key = '$legacy' WHERE id = 1");
    $db->exec("UPDATE user SET api_key = '' WHERE id = 2");

    require WALLOS_ROOT . '/migrations/000080.php';
    require WALLOS_ROOT . '/migrations/000080.php';

    assert_same(1, (int) $db->querySingle('SELECT COUNT(*) FROM api_keys'), 'one key, once, and none for an empty value');
    $row = $db->querySingle('SELECT * FROM api_keys', true);
    assert_same('Legacy key', $row['name'], 'named as a legacy key');
    assert_same('read,write', $row['scopes'], 'with read and write');
    assert_same(hash('sha256', $legacy), $row['hash'], 'hashed');
    assert_same('ab12ab12', $row['prefix'], 'with a short prefix');

    $result = wallos_api_authorize($db, $legacy, 'ical');
    assert_true($result['ok'], 'an old calendar URL keeps working');
    assert_same(1, (int) $result['user']['id'], 'for its owner');
    assert_true(wallos_api_authorize($db, $legacy, 'write')['ok'], 'old scripts can still write');
    assert_same(false, wallos_api_authorize($db, $legacy, 'admin:read')['ok'], 'but not read other accounts');
    $db->close();
});

wallos_test('failed lookups are rate limited per client', function () {
    $db = wallos_test_open_database();
    $now = 1_700_000_000;

    for ($i = 0; $i < WALLOS_API_MAX_FAILURES - 1; $i++) {
        wallos_api_record_failure($db, '203.0.113.5', $now + $i);
    }
    assert_same(false, wallos_api_is_rate_limited($db, '203.0.113.5', $now + 30), 'under the limit');
    wallos_api_record_failure($db, '203.0.113.5', $now + 30);
    assert_same(true, wallos_api_is_rate_limited($db, '203.0.113.5', $now + 31), 'at the limit');
    assert_same(false, wallos_api_is_rate_limited($db, '198.51.100.7', $now + 31), 'other clients are not affected');
    assert_same(false, wallos_api_is_rate_limited($db, '203.0.113.5', $now + WALLOS_API_FAILURE_WINDOW + 1), 'the window passes');

    wallos_api_record_failure($db, '203.0.113.5', $now + WALLOS_API_FAILURE_WINDOW + 2);
    assert_same(1, (int) $db->querySingle("SELECT failures FROM api_auth_failures WHERE client = '203.0.113.5'"), 'and the count starts over');
    $db->close();
});

wallos_test('creating an admin:read key needs the password, a TOTP code or a fresh OIDC sign-in', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    $stmt = $db->prepare('UPDATE user SET password = :password WHERE id = 1');
    $stmt->bindValue(':password', password_hash('correct horse', PASSWORD_DEFAULT), SQLITE3_TEXT);
    $stmt->execute();
    $now = 1_700_000_000;

    assert_true(wallos_api_reauthenticate($db, 1, 'correct horse', '', [], $now), 'the password');
    assert_same(false, wallos_api_reauthenticate($db, 1, 'wrong', '', [], $now), 'not a wrong one');
    assert_same(false, wallos_api_reauthenticate($db, 1, '', '123456', [], $now), 'no TOTP code without 2FA');
    assert_true(wallos_api_reauthenticate($db, 1, '', '', ['from_oidc' => true, 'oidc_login_at' => $now - 60], $now), 'an OIDC sign-in a minute ago');
    assert_same(false, wallos_api_reauthenticate($db, 1, '', '', ['from_oidc' => true, 'oidc_login_at' => $now - 3600], $now), 'not an hour ago');
    assert_same(false, wallos_api_reauthenticate($db, 1, '', '', ['oidc_login_at' => $now], $now), 'and only for an OIDC session');
    $db->close();
});

wallos_test('every API endpoint authenticates through api_auth with the right scope', function () {
    $files = glob(WALLOS_ROOT . '/api/*/*.php');
    assert_true(count($files) >= 27, 'the API files were found (' . count($files) . ')');

    foreach ($files as $file) {
        $relative = substr($file, strlen(WALLOS_ROOT) + 1);
        $source = file_get_contents($file);
        $base = basename($file);
        $isAdmin = strpos($relative, 'api/admin/') === 0;

        if ($base === 'get_ical_feed.php') {
            $scope = 'ical';
        } elseif ($isAdmin) {
            $scope = strpos($base, 'get_') === 0 ? 'admin:read' : 'write';
        } else {
            $scope = strpos($base, 'set_') === 0 ? 'write' : 'read';
        }

        assert_contains("wallos_api_require(\$db, '" . $scope . "'", $source, $relative . ' requires the ' . $scope . ' scope');
        assert_not_contains('WHERE api_key = :apiKey', $source, $relative . ' no longer looks up user.api_key');
        if ($isAdmin) {
            assert_contains("'requireAdmin' => true", $source, $relative . ' requires an admin owner');
        }
    }
});
