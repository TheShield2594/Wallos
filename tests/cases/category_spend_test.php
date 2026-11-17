<?php
/*
  Dashboard "By category" card (includes/category_spend.php).
*/

require_once WALLOS_ROOT . '/includes/category_spend.php';

wallos_test('category spend rows are sorted by cost with shares and the sidebar colour', function () {
    $spend = wallos_category_spend_rows(
        [2 => 10.0, 3 => 30.0, 4 => 0.0, 5 => 60.0],
        [2 => 'Music', 3 => 'Video', 4 => 'Unused', 5 => 'Utilities']
    );

    assert_same(['Utilities', 'Video', 'Music'], array_column($spend['rows'], 'name'),
        'highest cost first, zero-cost categories left out');
    assert_same(3, $spend['categories'], 'counts only categories in use');
    assert_equals(100.0, $spend['total'], 'total of the categories in use');
    assert_equals(60.0, $spend['rows'][0]['share'], 'share is the percent of the total');
    assert_same((5 * 47) % 360, $spend['rows'][0]['hue'], 'each category keeps its sidebar hue');
    assert_contains('* 47) % 360', file_get_contents(WALLOS_ROOT . '/includes/header.php'),
        'the sidebar still derives the hue the same way');
});

wallos_test('category spend monthly view reads $categoryCost', function () {
    $spend = wallos_category_spend_monthly(
        [1 => ['cost' => 5.0, 'name' => 'No category'], 2 => ['cost' => 15.0, 'name' => 'Music']],
        [1 => 'Uncategorised']
    );

    assert_same(['Music', 'Uncategorised'], array_column($spend['rows'], 'name'),
        'display names override the stored names');
});

wallos_test('category spend in a pay period counts every charge inside the period', function () {
    $start = new DateTime('2026-03-01');
    $end = new DateTime('2026-03-31');
    $subscriptions = [
        // weekly: Mar 2, 9, 16, 23, 30 -> 5 charges
        ['category_id' => 2, 'price' => 2.0, 'currency_id' => 1, 'inactive' => 0, 'cycle' => 2, 'frequency' => 1,
            'next_payment' => '2026-03-02', 'start_date' => '2026-01-05', 'auto_renew' => 1],
        // yearly, not due this period -> nothing
        ['category_id' => 3, 'price' => 120.0, 'currency_id' => 1, 'inactive' => 0, 'cycle' => 4, 'frequency' => 1,
            'next_payment' => '2026-09-01', 'start_date' => '2025-09-01', 'auto_renew' => 1],
        // monthly in USD -> converted at 2x
        ['category_id' => 3, 'price' => 7.0, 'currency_id' => 2, 'inactive' => 0, 'cycle' => 3, 'frequency' => 1,
            'next_payment' => '2026-03-15', 'start_date' => '2025-03-15', 'auto_renew' => 1],
        // disabled -> nothing
        ['category_id' => 4, 'price' => 50.0, 'currency_id' => 1, 'inactive' => 1, 'cycle' => 3, 'frequency' => 1,
            'next_payment' => '2026-03-10', 'start_date' => '2025-03-10', 'auto_renew' => 1],
    ];

    $spend = wallos_category_spend_in_period($subscriptions, [2 => 'Fitness', 3 => 'Video', 4 => 'Gym'], $start, $end,
        function ($price, $currencyId) {
            return $currencyId == 2 ? $price * 2 : $price;
        });

    $byName = array_column($spend['rows'], 'cost', 'name');
    assert_equals(['Video' => 14.0, 'Fitness' => 10.0], $byName,
        'weekly charges add up, other currencies are converted, disabled ones are left out');
});

wallos_test('the dashboard renders the category card from the shared helper behind its setting', function () {
    $source = file_get_contents(WALLOS_ROOT . '/index.php');
    assert_contains('wallos_category_spend_monthly(', $source, 'the dashboard uses the shared helper');
    assert_contains("show_category_card", $source, 'the card honours its setting');
    assert_contains("subscriptions.php?category=", $source, 'each row links to the filtered list');
});
