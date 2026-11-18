<?php
/*
  Price history points for the details panel and the API (issue #2).
*/

require_once WALLOS_ROOT . '/includes/price_changes.php';

wallos_test('a subscription without changes has its current price since the start date', function () {
    $points = wallos_price_history_points(
        ['price' => '17.99', 'currency_id' => 1, 'cycle' => 3, 'frequency' => 1, 'start_date' => '2024-01-05'],
        []
    );
    assert_same(1, count($points), 'one point');
    assert_same('2024-01-05', $points[0]['date'], 'dated at the start');
    assert_equals(17.99, $points[0]['price'], 'the current price');
});

wallos_test('price history starts at the first old price and follows each change', function () {
    $subscription = ['price' => 24.0, 'currency_id' => 2, 'cycle' => 3, 'frequency' => 1, 'start_date' => '2024-01-05'];
    $changes = [
        ['id' => 2, 'changed_at' => '2025-03-01', 'old_price' => 19.99, 'old_currency_id' => 1, 'old_cycle' => 3, 'old_frequency' => 1,
            'new_price' => 24.0, 'currency_id' => 2, 'new_cycle' => 3, 'new_frequency' => 1],
        ['id' => 1, 'changed_at' => '2024-06-01', 'old_price' => 17.99, 'old_currency_id' => 1, 'old_cycle' => 3, 'old_frequency' => 1,
            'new_price' => 19.99, 'currency_id' => 1, 'new_cycle' => 3, 'new_frequency' => 1],
    ];
    $points = wallos_price_history_points($subscription, $changes);

    assert_same(['2024-01-05', '2024-06-01', '2025-03-01'], array_column($points, 'date'), 'oldest first, from the start date');
    assert_same([17.99, 19.99, 24.0], array_column($points, 'price'), 'start price, then each new price');
    assert_same([1, 1, 2], array_column($points, 'currency_id'), 'each price keeps its own currency');
});

wallos_test('a start date after the first change dates the starting price at that change', function () {
    $points = wallos_price_history_points(
        ['price' => 12.0, 'currency_id' => 1, 'cycle' => 3, 'frequency' => 1, 'start_date' => '2026-01-01'],
        [['id' => 1, 'changed_at' => '2025-05-01', 'old_price' => 10.0, 'old_currency_id' => 1, 'old_cycle' => 3, 'old_frequency' => 1,
            'new_price' => 12.0, 'currency_id' => 1, 'new_cycle' => 3, 'new_frequency' => 1]]
    );
    assert_same('2025-05-01', $points[0]['date'], 'never after the first change');
});
