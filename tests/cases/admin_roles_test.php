<?php
/*
  Admin role (issue #45): user.is_admin replaces "user id 1 is the admin",
  OIDC role mapping syncs it on login, and the last admin is never removed.
*/

require_once WALLOS_ROOT . '/includes/admin_roles.php';
require_once WALLOS_ROOT . '/includes/oidc_settings.php';

/**
 * A database with only what migration 000079 touches, as it looked before.
 *
 * @param int[] $userIds
 * @return SQLite3
 */
function admin_roles_legacy_database(array $userIds)
{
    $db = new SQLite3(':memory:');
    $db->exec('CREATE TABLE user (id INTEGER PRIMARY KEY, username TEXT NOT NULL, oidc_sub TEXT)');
    $db->exec('CREATE TABLE oauth_settings (id INTEGER PRIMARY KEY, name TEXT, password_login_disabled INTEGER DEFAULT 0)');
    foreach ($userIds as $id) {
        $db->exec("INSERT INTO user (id, username) VALUES ($id, 'user$id')");
    }

    return $db;
}

function admin_roles_run_migration($db)
{
    require WALLOS_ROOT . '/migrations/000079.php';
}

wallos_test('migration 000079 makes user 1 the first admin, once', function () {
    $db = admin_roles_legacy_database([1, 2, 3]);
    admin_roles_run_migration($db);

    assert_same(1, (int) $db->querySingle('SELECT is_admin FROM user WHERE id = 1'), 'user 1 is an admin');
    assert_same(1, wallos_admin_count($db), 'nobody else is');
    assert_same('', $db->querySingle('SELECT roles_claim FROM oauth_settings') ?? '', 'roles_claim column exists');

    // A demotion made after the upgrade survives a second run.
    $db->exec('UPDATE user SET is_admin = 1 WHERE id = 2');
    $db->exec('UPDATE user SET is_admin = 0 WHERE id = 1');
    admin_roles_run_migration($db);
    assert_same(0, (int) $db->querySingle('SELECT is_admin FROM user WHERE id = 1'), 'running it again changes nothing');
    $db->close();
});

wallos_test('migration 000079 falls back to the oldest account without a user 1', function () {
    $db = admin_roles_legacy_database([4, 7]);
    admin_roles_run_migration($db);

    assert_same(1, (int) $db->querySingle('SELECT is_admin FROM user WHERE id = 4'), 'the oldest account is the admin');
    assert_same(1, wallos_admin_count($db), 'only one admin');
    $db->close();
});

wallos_test('the full schema has is_admin and the role mapping columns', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    wallos_test_create_user($db, 2, 'bob');

    assert_same(false, wallos_is_admin($db, 1), 'a new account is not an admin by default');
    $db->exec('UPDATE user SET is_admin = 1 WHERE id = 2');
    assert_same(true, wallos_is_admin($db, 2), 'is_admin decides, not the id');
    assert_same(true, is_admin($db, 2), 'the short alias agrees');
    assert_same(false, wallos_is_admin($db, 0), 'no account is not an admin');
    assert_same(false, wallos_is_admin($db, 99), 'a missing account is not an admin');
    assert_same(2, wallos_primary_admin_id($db), 'the oldest admin is the primary one');

    $settings = wallos_get_db_oidc_settings($db);
    $settings['roles_claim'] = 'groups';
    $settings['admin_values'] = 'tally-admins,ops';
    assert_true(wallos_save_db_oidc_settings($db, $settings), 'the OIDC settings save');
    $saved = wallos_get_db_oidc_settings($db);
    assert_same('groups', $saved['roles_claim'], 'the roles claim is stored');
    assert_same('tally-admins,ops', $saved['admin_values'], 'the admin values are stored');
    $db->close();
});

wallos_test('the last admin cannot be demoted or deleted', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    wallos_test_create_user($db, 2, 'bob');
    $db->exec('UPDATE user SET is_admin = 1 WHERE id = 1');

    $result = wallos_set_user_admin($db, 1, false);
    assert_same(false, $result['success'], 'demoting the only admin fails');
    assert_same('last_admin', $result['reason'], 'and says why');
    assert_same(true, wallos_is_admin($db, 1), 'alice is still an admin');
    assert_same(false, wallos_can_delete_user($db, 1), 'the only admin cannot be deleted');
    assert_same(true, wallos_can_delete_user($db, 2), 'a standard account can');

    assert_true(wallos_set_user_admin($db, 2, true)['changed'], 'bob is promoted');
    assert_true(wallos_can_delete_user($db, 1), 'with two admins, user 1 can be deleted');
    assert_true(wallos_set_user_admin($db, 1, false)['success'], 'and demoted');
    assert_same(false, wallos_is_admin($db, 1), 'alice is no longer an admin');
    assert_same(false, wallos_set_user_admin($db, 2, false)['success'], 'bob is now the last admin');

    assert_same('not_found', wallos_set_user_admin($db, 50, true)['reason'], 'a missing account is reported');
    $db->close();
});

wallos_test('claim values are read from lists and from separated strings', function () {
    assert_same(['a', 'b'], wallos_oidc_parse_claim_values(['a', 'b', 'a']), 'a list, de-duplicated');
    assert_same(['admins', 'users'], wallos_oidc_parse_claim_values('admins users'), 'space separated');
    assert_same(['admins', 'users'], wallos_oidc_parse_claim_values('admins, users'), 'comma separated');
    assert_same(['x', 'y', 'z'], wallos_oidc_parse_claim_values(['x', ['y', ['z']]]), 'nested lists are flattened');
    assert_same(['7'], wallos_oidc_parse_claim_values(7), 'a number is a value');
    assert_same([], wallos_oidc_parse_claim_values(null), 'nothing is no values');
    assert_same([], wallos_oidc_parse_claim_values(['k' => true]), 'booleans are ignored');
    assert_same(['tally-admins', 'ops'], wallos_oidc_parse_admin_values(' tally-admins , ops,, '), 'admin values are trimmed');

    $claims = ['groups' => ['a'], 'realm_access' => ['roles' => ['kc-admin']], 'a.b' => 'literal'];
    assert_same(['a'], wallos_oidc_claim_lookup($claims, 'groups'), 'a top-level claim');
    assert_same(['kc-admin'], wallos_oidc_claim_lookup($claims, 'realm_access.roles'), 'a dotted path');
    assert_same('literal', wallos_oidc_claim_lookup($claims, 'a.b'), 'a key with a dot wins over the path');
    assert_same(null, wallos_oidc_claim_lookup($claims, 'missing.path'), 'a missing path');
});

wallos_test('ID token claims are decoded from the payload', function () {
    $payload = rtrim(strtr(base64_encode(json_encode(['sub' => 'abc', 'groups' => ['tally-admins']])), '+/', '-_'), '=');
    $token = 'eyJhbGciOiJSUzI1NiJ9.' . $payload . '.signature';

    assert_same(['sub' => 'abc', 'groups' => ['tally-admins']], wallos_oidc_decode_id_token_claims($token), 'payload decoded');
    assert_same([], wallos_oidc_decode_id_token_claims('not-a-jwt'), 'garbage gives no claims');
    assert_same([], wallos_oidc_decode_id_token_claims(null), 'no token gives no claims');
    assert_same([], wallos_oidc_decode_id_token_claims('a.!!!.c'), 'a bad payload gives no claims');
});

wallos_test('the admin role is resolved from the ID token or user info', function () {
    assert_same(null, wallos_oidc_resolve_admin(['groups' => ['x']], [], '', 'x'), 'no claim configured');
    assert_same(null, wallos_oidc_resolve_admin(['groups' => ['x']], [], 'groups', ' , '), 'no admin values configured');
    assert_same(true, wallos_oidc_resolve_admin(['groups' => ['x', 'admins']], [], 'groups', 'admins'), 'from the ID token');
    assert_same(true, wallos_oidc_resolve_admin([], ['groups' => 'users admins'], 'groups', 'ops,admins'), 'from user info, as a string');
    assert_same(false, wallos_oidc_resolve_admin(['groups' => ['users']], ['groups' => ['staff']], 'groups', 'admins'), 'not listed');
    assert_same(false, wallos_oidc_resolve_admin([], [], 'groups', 'admins'), 'a missing claim is not admin');
    assert_same(false, wallos_oidc_resolve_admin([], ['groups' => ['Admins']], 'groups', 'admins'), 'values match exactly');
});

wallos_test('an OIDC login syncs the role but never demotes the last admin', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    wallos_test_create_user($db, 2, 'bob');
    $db->exec('UPDATE user SET is_admin = 1 WHERE id = 1');

    assert_same('not_configured', wallos_oidc_sync_admin($db, 2, null), 'nothing happens without role mapping');
    assert_same(false, wallos_is_admin($db, 2), 'bob is unchanged');

    assert_same('promoted', wallos_oidc_sync_admin($db, 2, true), 'bob is promoted by the claim');
    assert_same('unchanged', wallos_oidc_sync_admin($db, 2, true), 'and stays so');
    assert_same('demoted', wallos_oidc_sync_admin($db, 1, false), 'alice loses it while bob is an admin');

    $previousLog = ini_set('error_log', WALLOS_TEST_TMP . '/admin-roles-error.log');
    assert_same('kept_last_admin', wallos_oidc_sync_admin($db, 2, false), 'bob is the last admin and keeps it');
    ini_set('error_log', $previousLog === false ? '' : $previousLog);
    @unlink(WALLOS_TEST_TMP . '/admin-roles-error.log');
    assert_same(true, wallos_is_admin($db, 2), 'bob is still an admin');
    $db->close();
});

wallos_test('password login can be turned off only with an OIDC-linked admin', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    wallos_test_create_user($db, 2, 'bob');
    wallos_test_create_user($db, 3, 'carol');
    $db->exec('UPDATE user SET is_admin = 1 WHERE id = 1');

    assert_same(false, wallos_password_login_disable_allowed($db), 'no admin is linked to OIDC');
    $db->exec("UPDATE user SET oidc_sub = 'sub-bob' WHERE id = 2");
    assert_same(false, wallos_password_login_disable_allowed($db), 'a linked standard account is not enough');
    $db->exec("UPDATE user SET oidc_sub = 'sub-alice' WHERE id = 1");
    assert_same(true, wallos_password_login_disable_allowed($db), 'any number of accounts, with a linked admin');
    $db->close();
});

wallos_test('TALLY_FORCE_PASSWORD_LOGIN turns password login back on', function () {
    $db = wallos_test_open_database();
    $settings = wallos_get_db_oidc_settings($db);
    $settings['password_login_disabled'] = 1;
    wallos_save_db_oidc_settings($db, $settings);

    putenv('TALLY_FORCE_PASSWORD_LOGIN');
    $configuration = wallos_get_effective_oidc_configuration($db);
    assert_same(1, (int) $configuration['settings']['password_login_disabled'], 'disabled without the variable');

    putenv('TALLY_FORCE_PASSWORD_LOGIN=true');
    $configuration = wallos_get_effective_oidc_configuration($db);
    assert_same(0, (int) $configuration['settings']['password_login_disabled'], 'enabled with it');
    assert_same('TALLY_FORCE_PASSWORD_LOGIN', $configuration['managed_fields']['password_login_disabled'] ?? null, 'and the field is read-only');

    putenv('TALLY_FORCE_PASSWORD_LOGIN=false');
    assert_same(false, wallos_force_password_login(), 'false is off');
    putenv('TALLY_FORCE_PASSWORD_LOGIN');
    $db->close();
});

wallos_test('no code checks for user id 1 to mean admin any more', function () {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(WALLOS_ROOT, FilesystemIterator::SKIP_DOTS));
    $offenders = [];
    foreach ($files as $file) {
        $path = $file->getPathname();
        $relative = substr($path, strlen(WALLOS_ROOT) + 1);
        if (substr($path, -4) !== '.php' || $relative === 'includes/admin_roles.php' || preg_match('#^(libs|tests|migrations|\.git|\.claude)/#', $relative)) {
            continue;
        }
        $source = file_get_contents($path);
        if (preg_match('/\$(userId|user\[\'id\'\]|_SESSION\[\'userId\'\])\s*(!==?|===?)\s*1\b|\(int\)\s*\$userId\s*===\s*1\b/', $source)) {
            $offenders[] = $relative;
        }
    }

    assert_same([], $offenders, 'every admin check goes through wallos_is_admin()');
});
