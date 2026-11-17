<?php
/*
  Price and plan changes (includes/price_changes.php, issues #2 and #27).
*/

require_once WALLOS_ROOT . '/includes/price_changes.php';

function price_change_identity($amount, $from, $to)
{
    return $amount;
}

wallos_test('a price change row is made only when the cost changed', function () {
    $old = ['price' => 15.0, 'currency_id' => 1, 'cycle' => 3, 'frequency' => 1, 'tier_name' => 'Ultimate'];

    assert_same(null, wallos_price_change_row($old, $old, 'tier', '2026-03-01', 'price_change_identity'), 'nothing changed');
    assert_same(null, wallos_price_change_row($old, array_merge($old, ['price' => '15.001', 'tier_name' => 'Renamed']), 'tier', '2026-03-01', 'price_change_identity'),
        'a rename or a sub-cent difference is not a price change');

    $row = wallos_price_change_row($old, array_merge($old, ['price' => 10.0, 'tier_name' => 'Core']), 'tier', '2026-03-01', 'price_change_identity');
    assert_equals(15.0, $row['old_monthly'], 'old monthly');
    assert_equals(10.0, $row['new_monthly'], 'new monthly');
    assert_same('Ultimate', $row['old_tier'], 'old tier');
    assert_same('Core', $row['new_tier'], 'new tier');
    assert_same('tier', $row['reason'], 'a plan change');

    $yearly = wallos_price_change_row($old, array_merge($old, ['price' => 120.0, 'cycle' => 4]), 'bogus', '2026-03-01', 'price_change_identity');
    assert_equals(10.0, $yearly['new_monthly'], 'a yearly price compares per month');
    assert_same('tier', $yearly['reason'], 'unknown reason is a plan change');

    $noRate = wallos_price_change_row($old, array_merge($old, ['price' => 12.0, 'currency_id' => 2]), 'tier', '2026-03-01', fn() => null);
    assert_equals(12.0, $noRate['new_price'], 'the prices are kept without a rate');
    assert_same(null, $noRate['old_monthly'], 'no old monthly amount without a rate');
    assert_same(null, $noRate['new_monthly'], 'and no new one, so nothing compares two currencies');

    $oneTime = wallos_price_change_row($old, array_merge($old, ['cycle' => 5]), 'tier', '2026-03-01', 'price_change_identity');
    assert_same(null, $oneTime['new_monthly'], 'a one-time purchase has no monthly amount');
});

wallos_test('tier names are trimmed and capped', function () {
    assert_same(null, wallos_normalize_tier_name('   '), 'empty is null');
    assert_same('Game Pass Core', wallos_normalize_tier_name("  Game   Pass\tCore "), 'whitespace collapsed');
    assert_same(60, mb_strlen(wallos_normalize_tier_name(str_repeat('é', 80))), 'at most 60 characters');
    assert_same(null, wallos_normalize_tier_name(['x']), 'not a string');
});

wallos_test('price changes are recorded, listed and converted between currencies', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    $eur = wallos_test_currency_id(1, 0);
    $usd = wallos_test_currency_id(1, 1);
    $db->exec("INSERT INTO subscriptions (id, user_id, name, price, currency_id, next_payment, cycle, frequency, inactive)
        VALUES (50, 1, 'Game Pass', 17.99, $eur, '2026-04-01', 3, 1, 0)");

    $old = ['price' => 17.99, 'currency_id' => $eur, 'cycle' => 3, 'frequency' => 1, 'tier_name' => 'Ultimate'];
    assert_true(wallos_record_price_change($db, 1, 50, $old, $old, 'tier', '2026-01-10'), 'no change is fine');
    assert_true(wallos_record_price_change($db, 1, 50, $old, array_merge($old, ['price' => 9.99, 'tier_name' => 'Core']), 'tier', '2026-01-10'), 'downgrade');
    $core = array_merge($old, ['price' => 9.99, 'tier_name' => 'Core']);
    assert_true(wallos_record_price_change($db, 1, 50, $core, array_merge($core, ['price' => 12.1, 'currency_id' => $usd]), 'provider', '2026-02-10'), 'currency and price');

    $changes = wallos_price_changes_list($db, 1, 50);
    assert_same(2, count($changes), 'two rows');
    assert_same('provider', $changes[0]['reason'], 'newest first');
    assert_equals(10.99, round((float) $changes[0]['old_monthly'], 2), 'old 9.99 EUR in USD at 1.1');
    assert_equals(12.1, round((float) $changes[0]['new_monthly'], 2), 'new monthly in USD');
    assert_same([], wallos_price_changes_list($db, 2, 50), 'another account sees nothing');

    $snapshot = wallos_price_snapshot($db, 1, 50);
    assert_equals(17.99, $snapshot['price'], 'the snapshot reads the stored price');
    assert_same(null, wallos_price_snapshot($db, 2, 50), 'not another account\'s subscription');

    $db->close();
});

wallos_test('a change between currencies without a usable rate stores no monthly amounts', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    $eur = wallos_test_currency_id(1, 0);
    $usd = wallos_test_currency_id(1, 1);
    $db->exec("UPDATE currencies SET rate = 0 WHERE id = $usd");
    $db->exec("INSERT INTO subscriptions (id, user_id, name, price, currency_id, next_payment, cycle, frequency, inactive)
        VALUES (51, 1, 'News', 10, $eur, '2026-04-01', 3, 1, 0)");

    assert_same(null, wallos_convert_between_currencies(10, $eur, $usd, $db, 1), 'a zero rate gives no conversion');
    $old = ['price' => 10.0, 'currency_id' => $eur, 'cycle' => 3, 'frequency' => 1];
    assert_true(wallos_record_price_change($db, 1, 51, $old, array_merge($old, ['price' => 12.0, 'currency_id' => $usd]), 'provider', '2026-02-01'), 'still recorded');
    $change = wallos_price_changes_list($db, 1, 51)[0];
    assert_same(null, $change['old_monthly'], 'no unconverted old amount stored');
    assert_equals(12.0, $change['new_price'], 'the new price is kept');
    $db->close();
});

wallos_test('a partial edit keeps the price fields another edit saved', function () {
    // Read under the lock after another edit raised the price to 20 and renamed the tier.
    $locked = ['price' => 20.0, 'currency_id' => 3, 'cycle' => 3, 'frequency' => 1, 'tier_name' => 'Plus'];

    $nameOnly = wallos_price_fields_for_save($locked, []);
    assert_same(['price' => 20.0, 'currency_id' => 3, 'cycle' => 3, 'frequency' => 1, 'tier_name' => 'Plus'], $nameOnly,
        'an edit without price fields keeps what is stored');
    assert_true(!wallos_price_changed($locked, $nameOnly), 'and records no price change');

    $priceOnly = wallos_price_fields_for_save($locked, ['price' => '25', 'tier_name' => '  ']);
    assert_equals(25.0, $priceOnly['price'], 'a sent price is used');
    assert_same(3, $priceOnly['currency_id'], 'the rest stays as stored');
    assert_same(null, $priceOnly['tier_name'], 'an empty tier clears it');
    assert_contains('wallos_price_fields_for_save(', file_get_contents(WALLOS_ROOT . '/api/subscriptions/set_subscriptions.php'), 'the API uses it');
});

wallos_test('plan change totals split downgrades, upgrades and provider increases', function () {
    $changes = [
        ['changed_at' => '2026-01-01', 'old_monthly' => 18.0, 'new_monthly' => 10.0, 'currency_id' => 1, 'reason' => 'tier'],
        ['changed_at' => '2026-02-01', 'old_monthly' => 5.0, 'new_monthly' => 7.0, 'currency_id' => 1, 'reason' => 'tier'],
        ['changed_at' => '2026-03-01', 'old_monthly' => 11.0, 'new_monthly' => 12.1, 'currency_id' => 2, 'reason' => 'provider'],
        ['changed_at' => '2026-03-01', 'old_monthly' => null, 'new_monthly' => 7.0, 'currency_id' => 1, 'reason' => 'tier'],
        ['changed_at' => '2026-09-01', 'old_monthly' => 10.0, 'new_monthly' => 8.0, 'currency_id' => 1, 'reason' => 'tier'],
    ];
    $toMain = fn($amount, $currencyId) => (int) $currencyId === 2 ? $amount / 1.1 : $amount;

    $totals = wallos_price_change_totals($changes, '2026-04-01', $toMain);

    assert_same(4, $totals['count'], 'the one-time change is skipped');
    assert_equals(10.0, $totals['downgrade_monthly'], '8 + 2 a month saved');
    assert_equals(round(8 * 90 / 30.4375, 6), round($totals['downgrade_accrued'], 6), 'accrued since Jan 1; a future change adds nothing yet');
    assert_equals(2.0, $totals['upgrade_monthly'], 'the upgrade costs 2 a month');
    assert_equals(1.0, round($totals['provider_monthly'], 6), 'the provider increase, in the main currency');
});

wallos_test('the edit endpoints record price changes and deletions remove them', function () {
    assert_contains('wallos_record_price_change(', file_get_contents(WALLOS_ROOT . '/endpoints/subscription/add.php'), 'the form');
    assert_contains('wallos_record_price_change(', file_get_contents(WALLOS_ROOT . '/api/subscriptions/set_subscriptions.php'), 'the API');
    assert_contains('DELETE FROM subscription_price_changes', file_get_contents(WALLOS_ROOT . '/endpoints/subscription/delete.php'), 'deleting a subscription');
    assert_contains('DELETE FROM subscription_price_changes', file_get_contents(WALLOS_ROOT . '/api/subscriptions/set_subscriptions.php'), 'deleting through the API');
});
