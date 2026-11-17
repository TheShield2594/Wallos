<?php
/*
  "Monthly cost counts" (includes/monthly_cost.php, issue #26).
*/

require_once WALLOS_ROOT . '/includes/monthly_cost.php';

wallos_test('the monthly cost mode falls back to amortized', function () {
    assert_same('amortized', wallos_sanitize_monthly_cost_mode(null), 'no setting yet');
    assert_same('amortized', wallos_sanitize_monthly_cost_mode('weekly'), 'unknown value');
    assert_same('short_cycles', wallos_sanitize_monthly_cost_mode('short_cycles'), 'valid value kept');
    assert_same('cashflow', wallos_sanitize_monthly_cost_mode('cashflow'), 'valid value kept');
});

wallos_test('short cycles bill at least once a month', function () {
    assert_true(wallos_is_short_cycle(1, 30), 'every 30 days');
    assert_true(!wallos_is_short_cycle(1, 60), 'every 60 days is longer');
    assert_true(wallos_is_short_cycle(2, 4), 'every 4 weeks');
    assert_true(!wallos_is_short_cycle(2, 6), 'every 6 weeks is longer');
    assert_true(wallos_is_short_cycle(3, 1), 'monthly');
    assert_true(!wallos_is_short_cycle(3, 3), 'quarterly is longer');
    assert_true(!wallos_is_short_cycle(4, 1), 'yearly is longer');
    assert_true(!wallos_is_short_cycle(5, 1), 'one-time is not a cycle');
});

wallos_test('the API spreads prices like the pages do', function () {
    // getPricePerMonth() is a wrapper around it.
    assert_equals(10.0, wallos_price_per_month(4, 1, 120), 'yearly 120 is 10 a month');
    assert_equals(30.0, wallos_price_per_month(1, 1, 1), 'daily 1 is 30 a month');
    assert_equals(4.35, wallos_price_per_month(2, 1, 1), 'weekly 1 is 4.35 a month');
    assert_equals(5.0, wallos_price_per_month(3, 2, 10), 'every two months');
    assert_equals(0.0, wallos_price_per_month(5, 1, 99), 'one-time has no monthly cost');
    assert_equals(wallos_price_per_month(2, 3, 7), getPricePerMonth(2, 3, 7), 'the pages use the same function');
});

wallos_test('the headline monthly cost follows the mode', function () {
    assert_equals(50.0, wallos_headline_monthly_cost('amortized', 50, 20, 75), 'everything spread');
    assert_equals(20.0, wallos_headline_monthly_cost('short_cycles', 50, 20, 75), 'short cycles only');
    assert_equals(75.0, wallos_headline_monthly_cost('cashflow', 50, 20, 75), 'this month\'s charges');
    assert_equals(50.0, wallos_headline_monthly_cost('bogus', 50, 20, 75), 'unknown mode is amortized');
});

wallos_test('migration 000067 adds the monthly cost mode with the old behaviour as default', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    $db->exec("INSERT INTO settings (user_id) VALUES (1)");
    assert_same('amortized', $db->querySingle('SELECT monthly_cost_mode FROM settings WHERE user_id = 1'), 'default mode');
    $db->close();
});
