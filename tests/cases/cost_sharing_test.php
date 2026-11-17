<?php
/*
  Cost sharing and reimbursements (includes/cost_sharing.php, issue #34).
*/

require_once WALLOS_ROOT . '/includes/cost_sharing.php';

function cost_sharing_db()
{
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    $db->exec("DELETE FROM household WHERE user_id = 1");
    $db->exec("INSERT INTO household (id, name, user_id) VALUES (11, 'alice', 1), (12, 'Anna', 1), (13, 'Ben', 1)");

    return $db;
}

wallos_test('my cost is my share of the price less what is reimbursed', function () {
    $netflix = ['id' => 1, 'payer_user_id' => 11, 'reimbursed_percent' => 0];
    $shares = [11 => 25, 12 => 25, 13 => 50];
    assert_equals(0.25, wallos_my_cost_fraction($netflix, $shares, 11), 'a quarter');
    assert_equals(0.2, wallos_my_cost_fraction(array_merge($netflix, ['reimbursed_percent' => 20]), $shares, 11), 'a quarter less 20%');
    assert_equals(1.0, wallos_my_cost_fraction($netflix, [], 11), 'no split: the payer owns it');
    assert_equals(0.0, wallos_my_cost_fraction(array_merge($netflix, ['payer_user_id' => 12]), [], 11), 'someone else pays all of it');
    assert_equals(0.7, wallos_my_cost_fraction(['payer_user_id' => 11, 'reimbursed_percent' => 30], [], 11), 'charity tax relief');
});

wallos_test('members other than the payer owe their share each month', function () {
    $owed = wallos_amounts_owed(['payer_user_id' => 11], [11 => 25, 12 => 25, 13 => 50], 10.0);
    assert_equals([12 => 2.5, 13 => 5.0], $owed, 'Anna and Ben owe the payer');
    assert_same([], wallos_amounts_owed(['payer_user_id' => 11], [], 10.0), 'nothing owed without a split');
});

wallos_test('shares from the form must belong to the account and add up to 100', function () {
    $members = [11, 12, 13];
    $ok = wallos_shares_from_input(['11' => '25', '12' => '25', '13' => '50'], $members);
    assert_same(null, $ok['error'], 'valid split');
    assert_equals([11 => 25.0, 12 => 25.0, 13 => 50.0], $ok['shares'], 'stored percentages');
    assert_same([], wallos_shares_from_input(['11' => '', '12' => ''], $members)['shares'], 'empty fields are no split');
    assert_true(wallos_shares_from_input(['11' => '40', '12' => '40'], $members)['error'] !== null, 'must total 100');
    assert_true(wallos_shares_from_input(['99' => '100'], $members)['error'] !== null, 'another account\'s member');
    assert_true(wallos_shares_from_input(['11' => '150'], $members)['error'] !== null, 'over 100');
    assert_equals([12 => 100.0], wallos_shares_from_input(['12' => '100'], $members)['shares'], 'one member can carry it all');
});

wallos_test('shares are stored per subscription and replaced on save', function () {
    $db = cost_sharing_db();
    assert_true(wallos_store_subscription_shares($db, 5, 1, [11 => 50, 12 => 50]), 'first save');
    assert_true(wallos_store_subscription_shares($db, 5, 1, [11 => 25, 13 => 75]), 'second save');
    assert_equals([5 => [11 => 25.0, 13 => 75.0]], wallos_load_subscription_shares($db, 1), 'replaced, not appended');
    assert_same(11, wallos_self_member_id($db, 1), 'the first member is the account holder');
    assert_true(wallos_store_subscription_shares($db, 5, 1, []), 'clearing');
    assert_same([], wallos_load_subscription_shares($db, 1), 'no split left');
    $db->close();
});

wallos_test('the statistics view defaults to the setting and rows scale to my cost', function () {
    assert_same('gross', wallos_stats_cost_mode(null, 0), 'gross by default');
    assert_same('net', wallos_stats_cost_mode(null, 1), 'setting picks my cost');
    assert_same('gross', wallos_stats_cost_mode('gross', 1), 'the page switch wins');
    assert_same('gross', wallos_stats_cost_mode('bogus', 0), 'unknown value');
    $rows = wallos_apply_my_cost([
        ['id' => 1, 'price' => 10, 'intro_price' => 4, 'payer_user_id' => 11, 'reimbursed_percent' => 0],
        ['id' => 2, 'price' => 30, 'payer_user_id' => 12, 'reimbursed_percent' => 0, 'shared_owner_name' => 'Bob'],
    ], [1 => [11 => 25, 12 => 75]], 11);
    assert_equals(2.5, $rows[0]['price'], 'scaled price');
    assert_equals(1.0, $rows[0]['intro_price'], 'scaled intro price');
    assert_equals(10, wallos_gross_row($rows[0])['price'], 'full price kept');
    assert_equals(30, $rows[1]['price'], 'another account\'s shared row is left alone');
});

wallos_test('migration 000072 adds shares, reimbursements and the statistics default', function () {
    $db = cost_sharing_db();
    $db->exec("INSERT INTO settings (user_id) VALUES (1)");
    assert_same(0, $db->querySingle('SELECT stats_net_cost FROM settings WHERE user_id = 1'), 'gross by default');
    $row = $db->query("SELECT dflt_value FROM pragma_table_info('subscriptions') WHERE name = 'reimbursed_percent'")->fetchArray(SQLITE3_ASSOC);
    assert_same('0', (string) $row['dflt_value'], 'nothing reimbursed by default');
    assert_true($db->exec("INSERT INTO subscription_shares (subscription_id, household_id, share_percent, user_id) VALUES (1, 11, 50, 1)"), 'table exists');
    assert_true(!@$db->exec("INSERT INTO subscription_shares (subscription_id, household_id, share_percent, user_id) VALUES (1, 11, 50, 1)"), 'one share per member');
    $db->close();
});

wallos_test('deleting a subscription or an account removes its shares', function () {
    foreach (['endpoints/subscription/delete.php', 'api/subscriptions/set_subscriptions.php', 'endpoints/settings/deleteaccount.php', 'endpoints/admin/deleteuser.php'] as $file) {
        assert_contains('DELETE FROM subscription_shares', file_get_contents(WALLOS_ROOT . '/' . $file), $file);
    }
});
