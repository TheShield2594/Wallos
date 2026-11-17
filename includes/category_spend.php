<?php
/*
  Spend by category for the dashboard's "By category" card.

  Two views:
    monthly      the normalised monthly cost per category, the same figures
                 as the "Category split" chart on the statistics page
                 ($categoryCost from stats_calculations.php);
    pay period   what is actually charged per category between the start and
                 end of the current budget period, counted from the payment
                 dates the recurrence engine yields (the same rule as
                 computeAmountNeededInPeriod()).

  Rows are sorted by cost, highest first. Each category keeps the colour its
  dot has in the sidebar (includes/header.php), so the colour does not move
  when the two views rank categories differently.
*/

require_once __DIR__ . '/recurrence.php';
require_once __DIR__ . '/currency_rates.php';
require_once __DIR__ . '/intro_pricing.php';

/**
 * Hue of a category's dot, as the sidebar draws it.
 *
 * @param int $categoryId
 * @return int 0-359
 */
function wallos_category_hue($categoryId)
{
    return ((int) $categoryId * 47) % 360;
}

/**
 * Sorts per-category totals and works out each one's share.
 *
 * @param array $costs category id => cost (in the main currency)
 * @param array $names category id => display name
 * @return array{rows: array, total: float, categories: int}
 *         rows: [id, name, cost, share (0-100), hue, rank]; only categories
 *         with a cost above zero; categories: how many are in use
 */
function wallos_category_spend_rows(array $costs, array $names)
{
    $rows = [];
    $total = 0.0;
    foreach ($costs as $id => $cost) {
        $cost = (float) $cost;
        if ($cost <= 0) {
            continue;
        }
        $total += $cost;
        $rows[] = ['id' => (int) $id, 'name' => (string) ($names[$id] ?? ''), 'cost' => $cost];
    }

    usort($rows, function ($a, $b) {
        return $b['cost'] <=> $a['cost'] ?: strnatcasecmp($a['name'], $b['name']);
    });

    foreach ($rows as $rank => &$row) {
        $row['rank'] = $rank;
        $row['share'] = $total > 0 ? $row['cost'] / $total * 100 : 0;
        $row['hue'] = wallos_category_hue($row['id']);
    }
    unset($row);

    return ['rows' => $rows, 'total' => $total, 'categories' => count($rows)];
}

/**
 * Monthly cost per category from stats_calculations.php's $categoryCost.
 *
 * @param array $categoryCost id => ['cost' => float, 'name' => string]
 * @param array $names        id => display name (overrides $categoryCost names)
 * @return array see wallos_category_spend_rows()
 */
function wallos_category_spend_monthly(array $categoryCost, array $names = [])
{
    $costs = [];
    $labels = [];
    foreach ($categoryCost as $id => $entry) {
        $costs[$id] = $entry['cost'] ?? 0;
        $labels[$id] = $names[$id] ?? ($entry['name'] ?? '');
    }

    return wallos_category_spend_rows($costs, $labels);
}

/**
 * What is charged per category between two dates (inclusive), in the main
 * currency: every payment date of every active subscription times its price.
 * A manually renewed subscription only counts its next payment.
 *
 * @param array    $subscriptions subscriptions rows (need category_id, price,
 *                                currency_id, inactive and the schedule columns)
 * @param array    $names         category id => display name
 * @param callable $convert       fn(price, currencyId): float in the main currency
 * @return array see wallos_category_spend_rows()
 */
function wallos_category_spend_in_period(array $subscriptions, array $names, DateTimeInterface $start, DateTimeInterface $end, callable $convert)
{
    $costs = [];
    foreach ($subscriptions as $subscription) {
        if ((int) ($subscription['inactive'] ?? 0) !== 0) {
            continue;
        }
        $occurrences = wallos_occurrences_in_range($subscription, $start, $end, false);
        if ($occurrences === []) {
            continue;
        }
        $categoryId = (int) ($subscription['category_id'] ?? 1);
        $amount = wallos_price_of_occurrences($subscription, $occurrences);
        $costs[$categoryId] = ($costs[$categoryId] ?? 0) + $convert($amount, $subscription['currency_id']);
    }

    return wallos_category_spend_rows($costs, $names);
}
