<?php
/*
  Active months (issue #25): an annual obligation paid in seasonal
  installments, such as a tax paid monthly from September to December.
*/

require_once WALLOS_ROOT . '/includes/recurrence.php';
require_once WALLOS_ROOT . '/includes/monthly_cost.php';

const SEP_TO_DEC = (1 << 8) | (1 << 9) | (1 << 10) | (1 << 11);

function active_months_subscription(array $overrides = [])
{
    return array_merge([
        'cycle' => 3,
        'frequency' => 1,
        'next_payment' => '2030-09-15',
        'start_date' => '2030-09-15',
        'active_months' => SEP_TO_DEC,
        'auto_renew' => 1,
    ], $overrides);
}

wallos_test('the mask is built from month numbers and validated', function () {
    assert_same(SEP_TO_DEC, wallos_active_months_from_list(['9', '10', '11', '12']), 'Sep-Dec');
    assert_same(3840, SEP_TO_DEC, 'documented API value');
    assert_same(WALLOS_ALL_MONTHS, wallos_active_months_from_list([]), 'nothing picked is every month');
    assert_same(WALLOS_ALL_MONTHS, wallos_sanitize_active_months(0), 'zero');
    assert_same(WALLOS_ALL_MONTHS, wallos_sanitize_active_months(5000), 'too large');
    assert_same(WALLOS_ALL_MONTHS, wallos_subscription_active_months(active_months_subscription(['cycle' => 4])), 'yearly ignores the mask');
});

wallos_test('a monthly plan limited to Sep-Dec steps from December to the next September', function () {
    $subscription = active_months_subscription();
    assert_same('2030-10-15', wallos_next_occurrence($subscription, new DateTime('2030-09-15'))->format('Y-m-d'), 'Sep to Oct');
    assert_same('2031-09-15', wallos_next_occurrence($subscription, new DateTime('2030-12-15'))->format('Y-m-d'), 'Dec to next Sep');
    assert_same('2030-12-15', wallos_previous_occurrence($subscription, new DateTime('2031-09-15'))->format('Y-m-d'), 'back from Sep to Dec');
    assert_same('2031-10-15', wallos_step_occurrence(new DateTime('2030-11-15'), $subscription, 3)->format('Y-m-d'), 'three steps across the gap');
});

wallos_test('the calendar and budgets only see payments in active months', function () {
    $subscription = active_months_subscription();
    $dates = array_map(function ($date) {
        return $date->format('Y-m-d');
    }, wallos_occurrences_in_range($subscription, new DateTime('2030-01-01'), new DateTime('2031-12-31')));
    assert_same(['2030-09-15', '2030-10-15', '2030-11-15', '2030-12-15', '2031-09-15', '2031-10-15', '2031-11-15', '2031-12-15'], $dates, 'eight installments over two years');
    assert_same([], wallos_occurrences_in_range($subscription, new DateTime('2031-02-01'), new DateTime('2031-02-28')), 'nothing in February');
});

wallos_test('the renewal job advances past inactive months', function () {
    $subscription = active_months_subscription(['next_payment' => '2030-12-15']);
    $result = wallos_advance_schedule($subscription, new DateTime('2031-03-01'));
    assert_same(['2030-12-15'], $result['due_dates'], 'only December was due');
    assert_same('2031-09-15', $result['next_payment'], 'next is September');
});

wallos_test('a next payment in an inactive month moves to the first active one', function () {
    $subscription = active_months_subscription(['next_payment' => '2031-02-15']);
    assert_same('2031-09-15', wallos_first_active_occurrence($subscription, new DateTime('2031-02-15'))->format('Y-m-d'), 'February moves to September');
    assert_same('2031-10-15', wallos_first_active_occurrence($subscription, new DateTime('2031-10-15'))->format('Y-m-d'), 'an active date stays');
});

wallos_test('the monthly cost spreads the active payments over twelve months', function () {
    // ellite/Wallos#1148: 4 x 250 a year is about 83 a month.
    assert_equals(1000 / 12, wallos_price_per_month(3, 1, 250, SEP_TO_DEC), 'monthly Sep-Dec');
    assert_equals(250.0, wallos_price_per_month(3, 1, 250), 'every month is unchanged');
    assert_equals(250.0, wallos_price_per_month(3, 1, 250, WALLOS_ALL_MONTHS), 'full mask is unchanged');
    assert_equals(10.0, wallos_price_per_month(4, 1, 120, SEP_TO_DEC), 'yearly ignores the mask');
    $year = (int) date('Y');
    $days = 30 + 31 + 30 + 31;
    $daysInYear = (int) date('z', mktime(0, 0, 0, 12, 31, $year)) + 1;
    assert_equals(round(30 * $days / $daysInYear, 6), round(wallos_price_per_month(1, 1, 1, SEP_TO_DEC), 6), 'daily counts the active days of the year');
    assert_equals(1000 / 12, wallos_subscription_price_per_month(['cycle' => 3, 'frequency' => 1, 'price' => 250, 'active_months' => SEP_TO_DEC]), 'from a row');
});

wallos_test('the active months read as ranges, including across December', function () {
    assert_same('Sep–Dec', wallos_active_months_label(SEP_TO_DEC, 'en'), 'one range');
    assert_same('Nov–Feb', wallos_active_months_label((1 << 10) | (1 << 11) | 1 | (1 << 1), 'en'), 'wraps around the year');
    assert_same('Jan, Mar–May', wallos_active_months_label(1 | (1 << 2) | (1 << 3) | (1 << 4), 'en'), 'two ranges');
    assert_same('', wallos_active_months_label(WALLOS_ALL_MONTHS, 'en'), 'every month has no label');
});

wallos_test('only schedules that bill at least monthly can skip months', function () {
    assert_true(wallos_cycle_supports_active_months(3, 1), 'monthly');
    assert_true(wallos_cycle_supports_active_months(2, 4), 'every 4 weeks');
    assert_true(wallos_cycle_supports_active_months(1, 31), 'every 31 days');
    assert_true(!wallos_cycle_supports_active_months(3, 2), 'every two months');
    assert_true(!wallos_cycle_supports_active_months(1, 60), 'every 60 days');
    assert_true(!wallos_cycle_supports_active_months(4, 1), 'yearly');
    // Every two months, January and March active: the mask is ignored, so the
    // cost stays 120 / 2 instead of under-counting the two payments a year.
    assert_equals(60.0, wallos_price_per_month(3, 2, 120, 1 | (1 << 2)), 'monthly cost ignores the mask');
    assert_same(WALLOS_ALL_MONTHS, wallos_cycle_active_months(['cycle' => 3, 'frequency' => 2, 'active_months' => 5]), 'row mask ignored');
    assert_same(WALLOS_ALL_MONTHS, wallos_subscription_active_months(['cycle' => 3, 'frequency' => 2, 'active_months' => 5]), 'schedule mask ignored');
});

wallos_test('a schedule longer than a month bills every month it lands on', function () {
    // Every two months from January never lands in February.
    $subscription = active_months_subscription(['frequency' => 2, 'next_payment' => '2030-01-10', 'start_date' => '2030-01-10', 'active_months' => 1 << 1]);
    assert_same('2030-03-10', wallos_next_occurrence($subscription, new DateTime('2030-01-10'))->format('Y-m-d'), 'falls back to the plain schedule');
});

wallos_test('migration 000071 bills every month by default', function () {
    $db = wallos_test_open_database();
    $columns = [];
    $result = $db->query("SELECT name, dflt_value FROM pragma_table_info('subscriptions') WHERE name = 'active_months'");
    $row = $result->fetchArray(SQLITE3_ASSOC);
    assert_true($row !== false, 'column exists');
    assert_same('4095', (string) $row['dflt_value'], 'default is every month');
    $db->close();
});

wallos_test('no page keeps its own monthly price arithmetic', function () {
    foreach (['includes/stats_calculations.php', 'includes/list_subscriptions.php', 'endpoints/cronjobs/storetotalyearlycost.php',
        'endpoints/cronjobs/generaterecommendations.php', 'endpoints/ai/generate_recommendations.php'] as $file) {
        assert_not_contains('function getPricePerMonth(', file_get_contents(WALLOS_ROOT . '/' . $file), $file . ' uses includes/monthly_cost.php');
    }
});
