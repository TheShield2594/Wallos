<?php
/*
  The price distribution chart used fixed boundaries (5, 10, 20, 50), so in
  JPY, HUF or INR nearly every subscription fell into "50+".
  includes/price_histogram.php derives the boundaries from the prices.
*/

require_once WALLOS_ROOT . '/includes/price_histogram.php';

function price_histogram_total(array $dataPoints)
{
    return array_sum(array_column($dataPoints, 'y'));
}

wallos_test('price buckets keep familiar widths for low-value prices', function () {
    $prices = [3, 6, 8, 9.99, 12, 15, 18, 25];
    $dataPoints = wallos_price_histogram_data_points($prices);

    assert_same(
        ['0–5', '5–10', '10–15', '15+'],
        array_column($dataPoints, 'label'),
        'eight prices up to 25 give four buckets of 5'
    );
    assert_same(count($prices), price_histogram_total($dataPoints), 'every price lands in a bucket');
});

wallos_test('price buckets scale to high-value currencies', function () {
    $prices = [500, 980, 1490, 1990, 2200, 3000, 4990, 12000];
    $dataPoints = wallos_price_histogram_data_points($prices);
    $openBucket = end($dataPoints);

    assert_true(count($dataPoints) >= 3, 'yen prices still get several buckets');
    assert_true($openBucket['y'] < count($prices) / 2, 'most prices are not lumped into the open bucket');
    assert_same(count($prices), price_histogram_total($dataPoints), 'every price lands in a bucket');
});

wallos_test('price bucket widths round to readable numbers', function () {
    foreach ([[0.4, 0.5], [1.3, 2], [2.1, 2.5], [3, 5], [7, 10], [10, 10], [12, 20], [310, 500]] as [$width, $expected]) {
        assert_same((float) $expected, wallos_histogram_nice_width($width), "width $width rounds up to $expected");
    }
});

wallos_test('price buckets handle no prices and all-zero prices', function () {
    assert_same([], wallos_price_histogram_data_points([]), 'no prices, no buckets');

    $dataPoints = wallos_price_histogram_data_points([0, 0, 0]);
    assert_same(3, price_histogram_total($dataPoints), 'free subscriptions are still counted');
});
