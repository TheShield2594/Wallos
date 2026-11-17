<?php
/*
  Statistics over a period (includes/period_stats.php, issue #28).
*/

require_once WALLOS_ROOT . '/includes/period_stats.php';

wallos_test('the statistics period resolves calendar, pay and custom ranges', function () {
    $today = new DateTime('2026-03-20');

    $normal = wallos_stats_period_range(null, null, null, $today, 'monthly', '2026-01-19');
    assert_same('normalized', $normal['period'], 'no period is per month');
    assert_same(null, $normal['start'], 'no range per month');

    $calendar = wallos_stats_period_range('calendar', null, null, $today, 'monthly', '2026-01-19');
    assert_same('2026-03-01', $calendar['start']->format('Y-m-d'), 'calendar month start');
    assert_same('2026-03-31', $calendar['end']->format('Y-m-d'), 'calendar month end');

    $pay = wallos_stats_period_range('pay', null, null, $today, 'monthly', '2026-01-19');
    assert_same('2026-03-19', $pay['start']->format('Y-m-d'), 'pay period from the 19th');
    assert_same('2026-04-18', $pay['end']->format('Y-m-d'), 'to the 18th');

    $custom = wallos_stats_period_range('custom', '2026-02-01', '2026-02-14', $today, 'monthly', '2026-01-19');
    assert_same('custom', $custom['period'], 'custom range');
    assert_same('2026-02-14', $custom['end']->format('Y-m-d'), 'custom end');

    foreach ([['2026-02-14', '2026-02-01'], ['2026-02-30', '2026-03-01'], ['nope', '2026-03-01'], ['2020-01-01', '2026-01-01']] as [$from, $to]) {
        $invalid = wallos_stats_period_range('custom', $from, $to, $today, 'monthly', '2026-01-19');
        assert_same('normalized', $invalid['period'], "invalid range $from..$to falls back");
        assert_same('invalid_range', $invalid['error'], "invalid range $from..$to is reported");
    }
});

wallos_test('period charges count each renewal in the range, in the main currency', function () {
    $subscriptions = [
        // weekly: Mar 2, 9, 16, 23, 30
        ['id' => 1, 'name' => 'Paper', 'category_id' => 2, 'payer_user_id' => 1, 'payment_method_id' => 1, 'price' => 2.0, 'currency_id' => 1,
            'inactive' => 0, 'cycle' => 2, 'frequency' => 1, 'next_payment' => '2026-03-02', 'start_date' => '2026-01-05', 'auto_renew' => 1],
        // yearly in USD, renews in March
        ['id' => 2, 'name' => 'Cloud', 'category_id' => 3, 'payer_user_id' => 2, 'payment_method_id' => 1, 'price' => 110.0, 'currency_id' => 2,
            'inactive' => 0, 'cycle' => 4, 'frequency' => 1, 'next_payment' => '2026-03-10', 'start_date' => '2024-03-10', 'auto_renew' => 1],
        // yearly, renews in June: nothing in March
        ['id' => 3, 'name' => 'Domain', 'category_id' => 3, 'payer_user_id' => 1, 'payment_method_id' => 1, 'price' => 15.0, 'currency_id' => 1,
            'inactive' => 0, 'cycle' => 4, 'frequency' => 1, 'next_payment' => '2026-06-01', 'start_date' => '2025-06-01', 'auto_renew' => 1],
        // disabled
        ['id' => 4, 'name' => 'Gym', 'category_id' => 2, 'payer_user_id' => 1, 'payment_method_id' => 2, 'price' => 30.0, 'currency_id' => 1,
            'inactive' => 1, 'cycle' => 3, 'frequency' => 1, 'next_payment' => '2026-03-05', 'start_date' => '2025-01-05', 'auto_renew' => 1],
    ];
    $convert = fn($amount, $currencyId) => (int) $currencyId === 2 ? $amount / 1.1 : $amount;

    $charges = wallos_period_charges($subscriptions, new DateTime('2026-03-01'), new DateTime('2026-03-31'), $convert);

    assert_same(6, $charges['count'], 'five weekly charges and one yearly');
    assert_equals(110.0, round($charges['total'], 2), '5 x 2 + 110 USD at 1.1');
    assert_same(['2026-03-02', '2026-03-09', '2026-03-10'], array_slice(array_column($charges['charges'], 'date'), 0, 3), 'by date');
    assert_equals(100.0, round($charges['by_category'][3], 2), 'the yearly charge goes to its category');
    assert_equals(10.0, $charges['by_category'][2], 'the disabled subscription is left out');
    assert_true(!isset($charges['by_payment'][2]), 'no charge on the disabled one\'s payment method');

    $points = wallos_period_split_points($charges['by_category'], [2 => 'News', 3 => 'Cloud &amp; web']);
    assert_same(['Cloud & web', 'News'], array_column($points, 'label'), 'largest first, names decoded');
});

wallos_test('the statistics page offers the period switch', function () {
    $source = file_get_contents(WALLOS_ROOT . '/stats.php');
    assert_contains('wallos_stats_period_range(', $source, 'stats.php resolves the period');
    assert_contains("'period_pay_period'", $source, 'the pay period option is shown');
});
