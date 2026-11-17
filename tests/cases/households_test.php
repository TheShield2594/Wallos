<?php
/*
  Shared households (includes/households.php): who sees and who edits a
  subscription that is not theirs.

  Every case runs on the real schema with real accounts, because the rule is
  security-sensitive: a mistake shows one account's subscriptions to another.
*/

require_once WALLOS_ROOT . '/includes/households.php';

/**
 * alice (1) owns the household, bob (2) is an editor, carol (3) a viewer,
 * dave (4) is invited but has not accepted, erin (5) is not involved.
 *
 * @return SQLite3
 */
function households_fixture()
{
    $db = wallos_test_open_database();
    foreach ([1 => 'alice', 2 => 'bob', 3 => 'carol', 4 => 'dave', 5 => 'erin'] as $id => $name) {
        wallos_test_create_user($db, $id, $name);
    }
    assert_true(wallos_household_create($db, 1, 'Home')['success'], 'alice creates the household');
    assert_true(wallos_household_invite($db, 1, 'bob', 'editor')['success'], 'alice invites bob');
    assert_true(wallos_household_invite($db, 1, 'carol@example.com', 'viewer')['success'], 'alice invites carol by email');
    assert_true(wallos_household_invite($db, 1, 'dave', 'viewer')['success'], 'alice invites dave');
    $householdId = wallos_household_membership($db, 1)['id'];
    assert_true(wallos_household_respond($db, 2, $householdId, true)['success'], 'bob accepts');
    assert_true(wallos_household_respond($db, 3, $householdId, true)['success'], 'carol accepts');

    return $db;
}

function households_add_subscription($db, $userId, $name, $shared = false)
{
    $stmt = $db->prepare("INSERT INTO subscriptions (user_id, name, price, currency_id, next_payment, cycle, frequency, inactive, category_id, payer_user_id, payment_method_id)
        VALUES (:userId, :name, 10, :currencyId, '2099-01-01', 3, 1, 0, 1, 1, 1)");
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':name', $name, SQLITE3_TEXT);
    $stmt->bindValue(':currencyId', wallos_test_currency_id($userId, 0), SQLITE3_INTEGER);
    $stmt->execute();
    $id = $db->lastInsertRowID();
    if ($shared) {
        assert_true(wallos_set_subscription_sharing($db, $userId, $id, true)['success'], "$name is shared");
    }

    return $id;
}

wallos_test('households: shared subscriptions are visible to active members only', function () {
    $db = households_fixture();
    $aliceShared = households_add_subscription($db, 1, 'Netflix', true);
    $alicePrivate = households_add_subscription($db, 1, 'Diary app');
    $bobShared = households_add_subscription($db, 2, 'Spotify Family', true);
    $erinPrivate = households_add_subscription($db, 5, 'Erin gym');

    assert_same('owner', wallos_subscription_access($db, 1, $aliceShared), 'the owner owns it');
    assert_same('editor', wallos_subscription_access($db, 2, $aliceShared), 'bob is an editor');
    assert_same('viewer', wallos_subscription_access($db, 3, $aliceShared), 'carol is a viewer');
    assert_same(null, wallos_subscription_access($db, 4, $aliceShared), 'a pending invite grants nothing');
    assert_same(null, wallos_subscription_access($db, 5, $aliceShared), 'an outsider sees nothing');
    assert_same(null, wallos_subscription_access($db, 2, $alicePrivate), 'private stays private to members too');
    assert_same('editor', wallos_subscription_access($db, 1, $bobShared), "the household owner may edit a member's shared item");
    assert_same(null, wallos_subscription_access($db, 1, $erinPrivate), "nobody reaches erin's subscriptions");

    assert_same([$aliceShared, $bobShared], array_map('intval', array_column(wallos_shared_subscriptions($db, 3), 'id')),
        "carol's shared list holds both shared items and nothing private");
    assert_same([], wallos_shared_subscriptions($db, 4), 'dave, still invited, has no shared list');
    assert_same([], wallos_shared_subscriptions($db, 5), 'erin has no shared list');
    assert_same([$erinPrivate], wallos_visible_subscription_ids($db, 5), 'erin sees only her own');
    $db->close();
});

wallos_test('households: edit access is refused to viewers and outsiders', function () {
    $db = households_fixture();
    $shared = households_add_subscription($db, 1, 'Netflix', true);

    assert_true(wallos_load_accessible_subscription($db, 2, $shared, 'edit') !== false, 'an editor may edit');
    assert_same(false, wallos_load_accessible_subscription($db, 3, $shared, 'edit'), 'a viewer may not edit');
    assert_true(wallos_load_accessible_subscription($db, 3, $shared, 'read') !== false, 'a viewer may read');
    assert_same(false, wallos_load_accessible_subscription($db, 5, $shared, 'read'), 'an outsider may not read');
    assert_same(false, wallos_load_accessible_subscription($db, 2, 999999, 'read'), 'a missing id is no access');
    assert_same(['success' => false, 'error' => 'not_owner'], wallos_set_subscription_sharing($db, 2, $shared, false),
        'only the owner changes who a subscription is shared with');
    $db->close();
});

wallos_test('households: leaving, removal and deletion make shared items private again', function () {
    $db = households_fixture();
    $bobShared = households_add_subscription($db, 2, 'Spotify Family', true);
    $aliceShared = households_add_subscription($db, 1, 'Netflix', true);

    assert_true(wallos_household_leave($db, 2)['success'], 'bob leaves');
    assert_same(null, wallos_subscription_access($db, 2, $aliceShared), 'bob no longer sees the household');
    assert_same(null, wallos_subscription_access($db, 3, $bobShared), "bob's item is no longer shared");
    assert_same(null, $db->querySingle("SELECT shared_household_id FROM subscriptions WHERE id = $bobShared"), "bob's item is private again");

    assert_true(wallos_household_remove_member($db, 1, 3)['success'], 'alice removes carol');
    assert_same(null, wallos_subscription_access($db, 3, $aliceShared), 'carol no longer sees it');

    assert_same(['success' => false, 'error' => 'owner_cannot_leave'], wallos_household_leave($db, 1), 'the owner deletes rather than leaves');
    assert_true(wallos_household_delete($db, 1)['success'], 'alice deletes the household');
    assert_same(null, $db->querySingle("SELECT shared_household_id FROM subscriptions WHERE id = $aliceShared"), 'everything is private again');
    assert_same(0, $db->querySingle('SELECT COUNT(*) FROM household_members'), 'no memberships or invites remain');
    $db->close();
});

wallos_test('households: one household per account, and only the owner manages it', function () {
    $db = households_fixture();
    $householdId = wallos_household_membership($db, 1)['id'];

    assert_same('already_in_household', wallos_household_create($db, 2, 'Bob home')['error'] ?? null, 'a member cannot start a second household');
    assert_same('already_in_household', wallos_household_invite($db, 1, 'bob', 'viewer')['error'] ?? null, 'an active member cannot be invited again');
    assert_same('not_owner', wallos_household_invite($db, 2, 'erin', 'viewer')['error'] ?? null, 'an editor cannot invite');
    assert_same('not_owner', wallos_household_remove_member($db, 2, 3)['error'] ?? null, 'an editor cannot remove');
    assert_same('not_owner', wallos_household_set_role($db, 3, 3, 'editor')['error'] ?? null, 'a viewer cannot promote themself');
    assert_same('user_not_found', wallos_household_invite($db, 1, 'nobody', 'viewer')['error'] ?? null, 'unknown accounts are refused');
    assert_same('cannot_invite_self', wallos_household_invite($db, 1, 'alice', 'viewer')['error'] ?? null, 'no self-invites');
    assert_same('invalid_role', wallos_household_invite($db, 1, 'erin', 'owner')['error'] ?? null, 'only viewer or editor');
    assert_same('no_invite', wallos_household_respond($db, 5, $householdId, true)['error'] ?? null, 'erin cannot join uninvited');

    assert_true(wallos_household_set_role($db, 1, 3, 'editor')['success'], 'the owner promotes carol');
    assert_same('editor', wallos_household_membership($db, 3)['role'], 'carol is an editor now');
    $db->close();
});

wallos_test('households: deleting an account removes its household and memberships', function () {
    $db = households_fixture();
    $bobShared = households_add_subscription($db, 2, 'Spotify Family', true);

    wallos_household_forget_user($db, 1);
    assert_same(0, $db->querySingle('SELECT COUNT(*) FROM households'), "alice's household is gone");
    assert_same(null, wallos_household_membership($db, 2), 'bob is no longer in it');
    assert_same(null, $db->querySingle("SELECT shared_household_id FROM subscriptions WHERE id = $bobShared"), "bob's item is private again");

    foreach (['endpoints/settings/deleteaccount.php', 'endpoints/admin/deleteuser.php'] as $path) {
        assert_contains('wallos_household_forget_user(', file_get_contents(WALLOS_ROOT . '/' . $path), "$path cleans up households");
    }
    $db->close();
});

wallos_test('households: shared rows show in the viewer currency of the same code', function () {
    $db = households_fixture();
    $shared = households_add_subscription($db, 1, 'Netflix', true);
    // alice pays in USD (her second fixture currency, rate 1.1 per EUR).
    $db->exec('UPDATE subscriptions SET currency_id = ' . wallos_test_currency_id(1, 1) . " WHERE id = $shared");

    $row = wallos_shared_subscriptions($db, 2)[0];
    assert_same(wallos_test_currency_id(2, 1), $row['currency_id'], "bob's USD currency stands in for alice's");
    assert_equals(10.0, $row['price'], 'the price is unchanged when the code matches');
    assert_same('alice', $row['shared_owner_name'], 'the owner is named');

    // bob has no USD: the price is converted to alice's main currency (EUR).
    $db->exec('DELETE FROM currencies WHERE id = ' . wallos_test_currency_id(2, 1));
    $row = wallos_shared_subscriptions($db, 2)[0];
    assert_same(wallos_test_currency_id(2, 0), $row['currency_id'], "bob's EUR");
    assert_equals(round(10 / 1.1, 6), round($row['price'], 6), 'converted with the owner rate');
    $db->close();
});

wallos_test('households: every subscription endpoint checks access through the shared helper', function () {
    foreach ([
        'endpoints/subscription/get.php' => 'wallos_load_accessible_subscription(',
        'endpoints/subscription/markpaid.php' => 'wallos_load_accessible_subscription(',
        'endpoints/subscription/undopaid.php' => 'wallos_load_accessible_subscription(',
        'endpoints/ledger/list.php' => 'wallos_load_accessible_subscription(',
        'endpoints/subscription/add.php' => 'wallos_load_accessible_subscription(',
    ] as $path => $needle) {
        assert_contains($needle, file_get_contents(WALLOS_ROOT . '/' . $path), "$path asks the household helper");
    }
    foreach (['endpoints/subscription/delete.php', 'endpoints/subscription/clone.php'] as $path) {
        assert_contains('user_id = :user', file_get_contents(WALLOS_ROOT . '/' . $path), "$path stays owner-only");
    }
});
