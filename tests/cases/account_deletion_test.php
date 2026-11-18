<?php
/*
  Deleting an account removes what belongs to it.

  Both deletion paths (the self-service one and the administrator one) named
  their tables by hand, and the hand-written list had fallen twelve tables
  behind the schema. Two of the twelve hold credentials: `login_tokens` and
  `password_resets`.

  That is not only untidiness. The `user` table is declared
  `id INTEGER PRIMARY KEY` with no AUTOINCREMENT, so SQLite hands a deleted id
  straight back to the next account created, and that account inherited the
  leftovers, including a remember-me token that still worked.

  The list below is read from the schema rather than written down again, so a
  table added by a future migration is covered by this test on the day it
  appears, without anybody remembering to come back here.
*/

/**
 * Every table the schema gives a user_id column.
 *
 * @param SQLite3 $db
 * @return string[]
 */
function account_deletion_user_tables($db)
{
    $tables = [];
    $result = $db->query("SELECT name FROM sqlite_master WHERE type = 'table'
                          AND name NOT LIKE 'sqlite_%' ORDER BY name");

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $columns = $db->query('PRAGMA table_info("' . $row['name'] . '")');

        while ($column = $columns->fetchArray(SQLITE3_ASSOC)) {
            if ($column['name'] === 'user_id') {
                $tables[] = $row['name'];
                break;
            }
        }
    }

    return $tables;
}

/**
 * The tables a deletion path names.
 *
 * @param string $path
 * @return string[]
 */
function account_deletion_tables_named($path)
{
    $source = file_get_contents(WALLOS_ROOT . '/' . $path);
    preg_match_all('/DELETE FROM\s+["\']?(\w+)["\']?/i', $source, $matches);

    return array_values(array_unique($matches[1]));
}

wallos_test('both deletion paths cover every table that belongs to a user', function () {
    $db = wallos_test_open_database();
    $userTables = account_deletion_user_tables($db);
    $db->close();

    // A guard that finds nothing passes every assertion after it.
    assert_true(count($userTables) >= 25,
        'the schema was read (' . count($userTables) . ' tables carry a user_id)');

    foreach ([
        'endpoints/settings/deleteaccount.php',
        'endpoints/admin/deleteuser.php',
    ] as $path) {
        $named = account_deletion_tables_named($path);
        $missing = array_values(array_diff($userTables, $named));

        assert_same([], $missing,
            $path . ' leaves nothing behind (missing: ' . implode(', ', $missing) . ')');
    }
});

wallos_test('the two paths agree with each other', function () {
    // They were two hand-maintained copies of one list, which is how they
    // drifted together and would drift apart. Whichever one a future table is
    // added to, this fails until it is added to both.
    $self = account_deletion_tables_named('endpoints/settings/deleteaccount.php');
    $admin = account_deletion_tables_named('endpoints/admin/deleteuser.php');

    sort($self);
    sort($admin);

    assert_same($self, $admin,
        'the self-service and administrator paths delete the same tables');
});

wallos_test('the credentials of a deleted account do not survive it', function () {
    // Named separately from the schema sweep above, because these two are the
    // reason the sweep is worth having: an id handed back out carries them to
    // whoever gets it next.
    foreach ([
        'endpoints/settings/deleteaccount.php',
        'endpoints/admin/deleteuser.php',
    ] as $path) {
        $named = account_deletion_tables_named($path);

        assert_true(in_array('login_tokens', $named, true),
            $path . ' removes the remember-me tokens');
        assert_true(in_array('password_resets', $named, true),
            $path . ' removes the outstanding password reset tokens');
    }
});

/*
  The deletes run in one transaction, so a failure part way leaves the account
  as it was rather than a user row gone and, say, stored Apprise credentials
  left behind while the endpoint reports success.
*/

require_once WALLOS_ROOT . '/includes/account_deletion.php';

/**
 * The full statements a deletion path hands to wallos_delete_account().
 *
 * @param string $path
 * @return string[]
 */
function account_deletion_statements($path)
{
    $source = file_get_contents(WALLOS_ROOT . '/' . $path);
    preg_match_all("/'(DELETE FROM \\w+ WHERE (?:user_)?id = :id)'/", $source, $matches);

    return $matches[1];
}

/**
 * Two users; user 2 owns a household with user 3 in it, a subscription shared
 * there, and Apprise credentials.
 *
 * @return SQLite3
 */
function account_deletion_fixture()
{
    $db = wallos_test_open_database();
    foreach ([2 => 'erin', 3 => 'frank'] as $id => $name) {
        wallos_test_create_user($db, $id, $name);
    }
    assert_true(wallos_household_create($db, 2, 'Flat')['success'], 'erin creates a household');
    assert_true(wallos_household_invite($db, 2, 'frank', 'viewer')['success'], 'erin invites frank');
    $householdId = wallos_household_membership($db, 2)['id'];
    assert_true(wallos_household_respond($db, 3, $householdId, true)['success'], 'frank accepts');

    $db->exec("INSERT INTO subscriptions (user_id, name, price, currency_id, next_payment, cycle, frequency, inactive, category_id, payer_user_id, payment_method_id, shared_household_id)
        VALUES (2, 'Netflix', 10, " . wallos_test_currency_id(2, 0) . ", '2099-01-01', 3, 1, 0, 1, 2, 1, $householdId)");
    $db->exec("INSERT INTO apprise_notifications (user_id, enabled, url, config_key) VALUES (2, 1, 'https://apprise.example', 'secret-key')");
    $db->exec("INSERT INTO login_tokens (user_id, token) VALUES (2, 'tok')");

    return $db;
}

function account_deletion_count($db, $sql)
{
    return (int) $db->querySingle($sql);
}

foreach ([
    'endpoints/settings/deleteaccount.php',
    'endpoints/admin/deleteuser.php',
] as $accountDeletionPath) {
    wallos_test('a failing delete leaves the account intact (' . $accountDeletionPath . ')', function () use ($accountDeletionPath) {
        $statements = account_deletion_statements($accountDeletionPath);
        assert_true(count($statements) >= 25, 'the endpoint statements were read (' . count($statements) . ')');

        $db = account_deletion_fixture();
        // Stand-in for a busy timeout or any other error late in the list,
        // after the user row and the household have already been deleted.
        $db->exec("CREATE TRIGGER fail_apprise_delete BEFORE DELETE ON apprise_notifications
                   BEGIN SELECT RAISE(ABORT, 'database is locked'); END");

        assert_same(false, wallos_delete_account($db, 2, $statements), 'the deletion reports failure');

        assert_same(1, account_deletion_count($db, 'SELECT COUNT(*) FROM user WHERE id = 2'), 'the user row is still there');
        assert_same(1, account_deletion_count($db, 'SELECT COUNT(*) FROM apprise_notifications WHERE user_id = 2'), 'the Apprise credentials are still there (and still owned)');
        assert_same(1, account_deletion_count($db, 'SELECT COUNT(*) FROM subscriptions WHERE user_id = 2'), 'the subscription is still there');
        assert_same(1, account_deletion_count($db, 'SELECT COUNT(*) FROM login_tokens WHERE user_id = 2'), 'the login token is still there');
        assert_same(2, account_deletion_count($db, 'SELECT COUNT(*) FROM currencies WHERE user_id = 2'), 'the currencies are still there');
        assert_same(1, account_deletion_count($db, 'SELECT COUNT(*) FROM households WHERE owner_user_id = 2'), 'the household is still there');
        assert_same(1, account_deletion_count($db, 'SELECT COUNT(*) FROM household_members WHERE user_id = 3'), 'its member is still in it');
        assert_same(1, account_deletion_count($db, 'SELECT COUNT(*) FROM subscriptions WHERE shared_household_id IS NOT NULL'), 'the subscription is still shared');

        // Nothing is left open: the retry, once the cause is gone, succeeds.
        $db->exec('DROP TRIGGER fail_apprise_delete');
        assert_true(wallos_delete_account($db, 2, $statements), 'the retry deletes the account');
        assert_same(0, account_deletion_count($db, 'SELECT COUNT(*) FROM user WHERE id = 2'), 'the user row is gone');
        assert_same(0, account_deletion_count($db, 'SELECT COUNT(*) FROM apprise_notifications WHERE user_id = 2'), 'the Apprise credentials are gone');
        assert_same(0, account_deletion_count($db, 'SELECT COUNT(*) FROM households'), 'the household is gone');
        assert_same(1, account_deletion_count($db, 'SELECT COUNT(*) FROM user WHERE id = 3'), 'the other account is untouched');

        $db->close();
    });
}

wallos_test('every user_id table is empty for a deleted account', function () {
    $db = account_deletion_fixture();
    $statements = account_deletion_statements('endpoints/settings/deleteaccount.php');

    assert_true(wallos_delete_account($db, 2, $statements), 'the deletion succeeds');
    foreach (account_deletion_user_tables($db) as $table) {
        assert_same(0, account_deletion_count($db, "SELECT COUNT(*) FROM \"$table\" WHERE user_id = 2"), "$table holds nothing of the deleted account");
    }

    $db->close();
});

wallos_test('a table a migration has not created yet does not block deletion', function () {
    $db = account_deletion_fixture();
    $db->exec('DROP TABLE subscription_shares');

    assert_true(wallos_delete_account($db, 2, account_deletion_statements('endpoints/admin/deleteuser.php')),
        'the deletion succeeds without the table');
    assert_same(0, account_deletion_count($db, 'SELECT COUNT(*) FROM user WHERE id = 2'), 'the user row is gone');

    $db->close();
});

wallos_test('deletion gives up without changing anything while another writer holds the database', function () {
    $db = account_deletion_fixture();
    $path = $db->querySingle('PRAGMA database_list', true)['file'];
    $db->busyTimeout(50);

    $other = new SQLite3($path);
    $other->exec('BEGIN IMMEDIATE');

    assert_same(false, wallos_delete_account($db, 2, account_deletion_statements('endpoints/settings/deleteaccount.php')),
        'the deletion reports failure');

    $other->exec('ROLLBACK');
    $other->close();
    assert_same(1, account_deletion_count($db, 'SELECT COUNT(*) FROM user WHERE id = 2'), 'the user row is still there');

    $db->close();
});
