<?php
/*
  Exchange-rate API keys per provider (includes/fixer_keys.php, issue #44).
*/

require_once WALLOS_ROOT . '/includes/fixer_keys.php';

wallos_test('a key is saved per provider and replacing one leaves the other', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');

    assert_true(wallos_fixer_store_key($db, 1, 0, 'fixer-key-0000'), 'fixer.io key');
    assert_true(wallos_fixer_store_key($db, 1, 1, 'apilayer-key-1111'), 'apilayer key');
    assert_true(wallos_fixer_store_key($db, 1, 1, 'apilayer-key-2222'), 'replaced');
    assert_true(!wallos_fixer_store_key($db, 1, 2, 'x'), 'frankfurter.dev keeps no key');

    assert_same([0 => 'fixer-key-0000', 1 => 'apilayer-key-2222'], wallos_fixer_saved_keys($db, 1), 'one key each');
    assert_same([], wallos_fixer_saved_keys($db, 2), 'another account has none');

    assert_true(wallos_fixer_clear_keys($db, 1), 'cleared');
    assert_same([], wallos_fixer_saved_keys($db, 1), 'nothing left');
    $db->close();
});

wallos_test('an empty key field resolves to the key saved for the provider', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    wallos_fixer_store_key($db, 1, 0, 'fixer-key-0000');

    assert_same('typed', wallos_fixer_resolve_key($db, 1, 0, ' typed '), 'a typed key wins');
    assert_same('fixer-key-0000', wallos_fixer_resolve_key($db, 1, 0, ''), 'the saved key');
    assert_same('', wallos_fixer_resolve_key($db, 1, 1, ''), 'no key saved for apilayer');

    // A key left on a frankfurter.dev row from before keys were per provider.
    $db->exec("INSERT INTO fixer (api_key, provider, user_id) VALUES ('legacy-key', 2, 1)");
    assert_same('legacy-key', wallos_fixer_resolve_key($db, 1, 1, ''), 'the legacy key is tried');
    assert_same('fixer-key-0000', wallos_fixer_resolve_key($db, 1, 0, ''), 'but a saved key comes first');
    $db->close();
});

wallos_test('key hints never show more than the last four characters', function () {
    assert_same('••••cdef', wallos_fixer_key_hint('0123456789abcdef'), 'last four');
    assert_same('••••', wallos_fixer_key_hint('short'), 'a short key shows nothing');
});

wallos_test('the migration copies existing keys for the provider they were saved with', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    wallos_test_create_user($db, 2, 'bob');
    wallos_test_create_user($db, 3, 'carol');
    $db->exec("INSERT INTO fixer (api_key, provider, user_id) VALUES ('alice-apilayer', 1, 1), ('bob-legacy', 2, 2), ('', 0, 3)");
    $db->exec("DELETE FROM fixer_keys");

    require WALLOS_ROOT . '/migrations/000076.php';
    require WALLOS_ROOT . '/migrations/000076.php';

    assert_same([1 => 'alice-apilayer'], wallos_fixer_saved_keys($db, 1), 'copied for apilayer');
    assert_same([], wallos_fixer_saved_keys($db, 2), 'a frankfurter row\'s key has no known provider');
    assert_same([], wallos_fixer_saved_keys($db, 3), 'an empty key is not copied');
    assert_same(1, (int) $db->querySingle('SELECT COUNT(*) FROM fixer_keys'), 'running twice adds nothing');
    $db->close();
});

wallos_test('removing a saved key drops it, and the active row only when that provider is active', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    wallos_fixer_store_key($db, 1, 0, 'fixer-key-0000');
    wallos_fixer_store_key($db, 1, 1, 'apilayer-key-1111');
    $db->exec("INSERT INTO fixer (api_key, provider, user_id) VALUES ('apilayer-key-1111', 1, 1)");

    assert_true(wallos_fixer_remove_key($db, 1, 0), 'fixer.io key removed');
    assert_same([1 => 'apilayer-key-1111'], wallos_fixer_saved_keys($db, 1), 'the other key stays');
    assert_same(1, (int) $db->querySingle('SELECT COUNT(*) FROM fixer WHERE user_id = 1'), 'apilayer stays active');

    assert_true(wallos_fixer_remove_key($db, 1, 1), 'the active key removed');
    assert_same(0, (int) $db->querySingle('SELECT COUNT(*) FROM fixer WHERE user_id = 1'), 'no provider configured any more');
    assert_true(!wallos_fixer_remove_key($db, 1, 2), 'frankfurter.dev has no key to remove');
    $db->close();
});
