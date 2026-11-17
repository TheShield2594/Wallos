<?php
/*
  Statistics over a date range: what is actually charged between two dates,
  rather than every subscription normalized to a month.

  The statistics page offers four periods (?period=):

    normalized  (default) every figure per month, as before
    calendar    the current calendar month
    pay         the current pay period (user.budget_period_type / _anchor_date)
    custom      ?from=Y-m-d&to=Y-m-d

  Charges come from the shared recurrence engine, so they agree with the
  calendar, the budget and the API. Dates before today are projected from the
  schedule, not read from the payment history.
*/

require_once __DIR__ . '/recurrence.php';
require_once __DIR__ . '/intro_pricing.php';
require_once __DIR__ . '/budget_period_calculations.php';

const WALLOS_STATS_PERIODS = ['normalized', 'calendar', 'pay', 'custom'];

/** The longest custom range, in days, so a typo cannot project decades. */
const WALLOS_STATS_MAX_RANGE_DAYS = 1100;

/**
 * Resolves the statistics period from the query.
 *
 * @param mixed    $period     ?period=
 * @param mixed    $from       ?from= (custom only)
 * @param mixed    $to         ?to=   (custom only)
 * @param DateTime $today
 * @param string   $payPeriodType
 * @param string   $payPeriodAnchor Y-m-d
 * @return array{period: string, start: ?DateTime, end: ?DateTime, error: ?string}
 *         start/end are null for 'normalized'; an invalid custom range falls
 *         back to 'normalized' with error 'invalid_range'
 */
function wallos_stats_period_range($period, $from, $to, DateTime $today, $payPeriodType, $payPeriodAnchor)
{
    $period = in_array($period, WALLOS_STATS_PERIODS, true) ? $period : 'normalized';
    $today = new DateTime($today->format('Y-m-d'));

    switch ($period) {
        case 'calendar':
            return [
                'period' => 'calendar',
                'start' => new DateTime($today->format('Y-m-01')),
                'end' => new DateTime($today->format('Y-m-t')),
                'error' => null,
            ];
        case 'pay':
            $active = getActiveBudgetPeriod($today, $payPeriodType, $payPeriodAnchor);
            return ['period' => 'pay', 'start' => $active['start'], 'end' => $active['end'], 'error' => null];
        case 'custom':
            $start = wallos_parse_date(is_string($from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from : null);
            $end = wallos_parse_date(is_string($to) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ? $to : null);
            if ($start === null || $end === null || $start->format('Y-m-d') !== $from || $end->format('Y-m-d') !== $to
                || $end < $start || (int) $start->diff($end)->format('%a') > WALLOS_STATS_MAX_RANGE_DAYS) {
                return ['period' => 'normalized', 'start' => null, 'end' => null, 'error' => 'invalid_range'];
            }
            return ['period' => 'custom', 'start' => $start, 'end' => $end, 'error' => null];
    }

    return ['period' => 'normalized', 'start' => null, 'end' => null, 'error' => null];
}

/**
 * Every charge of the active subscriptions between two dates (inclusive),
 * in the main currency, with totals per category, payer and payment method.
 *
 * @param array    $subscriptions subscriptions rows (inactive ones are skipped)
 * @param DateTime $start
 * @param DateTime $end
 * @param callable $convert fn(float $amount, int $currencyId): float, to the main currency
 * @return array{total: float, count: int, charges: array, by_category: array, by_member: array, by_payment: array}
 *         charges are sorted by date, then name
 */
function wallos_period_charges(array $subscriptions, DateTime $start, DateTime $end, callable $convert)
{
    $charges = [];
    $byCategory = [];
    $byMember = [];
    $byPayment = [];
    $total = 0.0;

    foreach ($subscriptions as $subscription) {
        if ((int) ($subscription['inactive'] ?? 0) !== 0) {
            continue;
        }
        foreach (wallos_occurrences_in_range($subscription, $start, $end) as $date) {
            $amount = (float) $convert(wallos_price_on($subscription, $date->format('Y-m-d')), $subscription['currency_id']);
            $charges[] = [
                'id' => $subscription['id'] ?? null,
                'name' => $subscription['name'],
                'date' => $date->format('Y-m-d'),
                'amount' => $amount,
                'logo' => $subscription['logo'] ?? null,
                'logo_variant' => $subscription['logo_variant'] ?? null,
                'logo_text_color' => $subscription['logo_text_color'] ?? null,
                'shared_owner_name' => $subscription['shared_owner_name'] ?? null,
            ];
            $total += $amount;
            // A household member's shared rows carry someone else's records.
            if (empty($subscription['shared_owner_name'])) {
                $byCategory[$subscription['category_id']] = ($byCategory[$subscription['category_id']] ?? 0) + $amount;
                $byMember[$subscription['payer_user_id']] = ($byMember[$subscription['payer_user_id']] ?? 0) + $amount;
                $byPayment[$subscription['payment_method_id']] = ($byPayment[$subscription['payment_method_id']] ?? 0) + $amount;
            }
        }
    }

    usort($charges, fn($a, $b) => strcmp($a['date'], $b['date']) ?: strnatcasecmp($a['name'], $b['name']));

    return [
        'total' => $total,
        'count' => count($charges),
        'charges' => $charges,
        'by_category' => $byCategory,
        'by_member' => $byMember,
        'by_payment' => $byPayment,
    ];
}

/**
 * Chart points from per-id totals, largest first, ids without a name dropped.
 *
 * @param array $totals id => amount
 * @param array $names  id => label
 * @return array[] [['label' => ..., 'y' => ...], ...]
 */
function wallos_period_split_points(array $totals, array $names)
{
    $points = [];
    foreach ($totals as $id => $amount) {
        if ($amount > 0 && isset($names[$id])) {
            $points[] = ['label' => html_entity_decode($names[$id]), 'y' => round($amount, 2)];
        }
    }
    usort($points, fn($a, $b) => $b['y'] <=> $a['y']);

    return $points;
}
