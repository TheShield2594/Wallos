<?php
/*
  What the dashboard and statistics "Monthly cost" counts, per the user's
  settings.monthly_cost_mode (migration 000067):

    amortized     every cycle spread evenly over the months (default)
    short_cycles  only cycles of a month or shorter; longer plans are shown
                  next to it as a yearly amount so they stay visible
    cashflow      what is charged in the current calendar month

  The monthly budget follows the same figure. Yearly totals and the yearly
  cost snapshots stay amortized.
*/

const WALLOS_MONTHLY_COST_MODES = ['amortized', 'short_cycles', 'cashflow'];

/**
 * @param mixed $mode
 * @return string one of WALLOS_MONTHLY_COST_MODES, 'amortized' when unknown
 */
function wallos_sanitize_monthly_cost_mode($mode)
{
    return in_array($mode, WALLOS_MONTHLY_COST_MODES, true) ? $mode : 'amortized';
}

/**
 * Price spread over one month. Every page, cron job and API endpoint uses
 * this (getPricePerMonth() is a wrapper kept for the pages).
 *
 * With active months (issue #25) the price counts only the payments of the
 * active months, spread over twelve: monthly Sep-Dec at 250 is 4 x 250 / 12.
 * Daily and weekly cycles count the days of the active months in the current
 * year.
 *
 * @param int   $cycle
 * @param int   $frequency
 * @param float $price
 * @param int   $activeMonths 12-bit mask, bit 0 = January (4095 = every month)
 * @return float
 */
function wallos_price_per_month($cycle, $frequency, $price, $activeMonths = 4095)
{
    $frequency = max(1, (int) $frequency);
    $price = (float) $price;
    switch ((int) $cycle) {
        case 1:
            $monthly = $price * (30 / $frequency);
            break;
        case 2:
            $monthly = $price * (4.35 / $frequency);
            break;
        case 3:
            $monthly = $price / $frequency;
            break;
        case 4:
            return $price / (12 * $frequency);
        default:
            return 0.0;
    }

    $activeMonths = is_numeric($activeMonths) ? (int) $activeMonths : 4095;
    // Only schedules that bill at least monthly can skip months (see
    // wallos_cycle_supports_active_months()); for them every active month is
    // reached, so the share below is exact for monthly and day-weighted for
    // daily and weekly plans.
    $limits = [1 => 31, 2 => 4, 3 => 1];
    if ($activeMonths < 1 || $activeMonths >= 4095 || $frequency > $limits[(int) $cycle]) {
        return $monthly;
    }

    return $monthly * wallos_active_months_share((int) $cycle, $activeMonths);
}

/**
 * The share of a year's payments that fall in the active months.
 *
 * @param int $cycle
 * @param int $activeMonths
 * @param int|null $year the current year when null
 * @return float 0-1
 */
function wallos_active_months_share($cycle, $activeMonths, $year = null)
{
    $activeCount = 0;
    $activeDays = 0;
    $year = $year ?? (int) date('Y');
    $daysInYear = (int) date('z', mktime(0, 0, 0, 12, 31, $year)) + 1;
    for ($month = 1; $month <= 12; $month++) {
        if (($activeMonths & (1 << ($month - 1))) !== 0) {
            $activeCount++;
            $activeDays += wallos_days_in_month($month, $year);
        }
    }

    if ((int) $cycle === 3) {
        return $activeCount / 12;
    }

    return $activeDays / $daysInYear;
}

function wallos_days_in_month($month, $year)
{
    return (int) date('t', mktime(0, 0, 0, $month, 1, $year));
}

/**
 * The monthly price of a subscriptions row (cycle, frequency, active months).
 *
 * @param array      $subscription
 * @param float|null $price the price to spread; the row's price when null
 * @return float
 */
function wallos_subscription_price_per_month(array $subscription, $price = null)
{
    return wallos_price_per_month(
        $subscription['cycle'] ?? 0,
        $subscription['frequency'] ?? 1,
        $price ?? ($subscription['price'] ?? 0),
        wallos_cycle_active_months($subscription)
    );
}

/**
 * The row's active months; every month for schedules longer than a month.
 *
 * @return int
 */
function wallos_cycle_active_months(array $subscription)
{
    $cycle = (int) ($subscription['cycle'] ?? 0);
    $frequency = (int) ($subscription['frequency'] ?? 1);
    $limits = [1 => 31, 2 => 4, 3 => 1];
    if (!isset($limits[$cycle]) || $frequency < 1 || $frequency > $limits[$cycle]) {
        return 4095;
    }
    $mask = $subscription['active_months'] ?? 4095;

    return is_numeric($mask) && (int) $mask >= 1 && (int) $mask <= 4095 ? (int) $mask : 4095;
}

if (!function_exists('getPricePerMonth')) {
    /**
     * @param int   $cycle
     * @param int   $frequency
     * @param float $price
     * @param int   $activeMonths
     * @return float
     */
    function getPricePerMonth($cycle, $frequency, $price, $activeMonths = 4095)
    {
        return wallos_price_per_month($cycle, $frequency, $price, $activeMonths);
    }
}

/**
 * Whether a schedule bills at least once a month: every 1-31 days, every 1-4
 * weeks or every month. Quarterly, yearly and one-time are longer.
 *
 * @return bool
 */
function wallos_is_short_cycle($cycle, $frequency)
{
    $frequency = (int) $frequency;
    switch ((int) $cycle) {
        case 1:
            return $frequency >= 1 && $frequency <= 31;
        case 2:
            return $frequency >= 1 && $frequency <= 4;
        case 3:
            return $frequency === 1;
    }

    return false;
}

/**
 * The headline monthly figure for a mode.
 *
 * @param string $mode
 * @param float  $amortized   every active subscription spread per month
 * @param float  $shortCycles the part of $amortized from short cycles
 * @param float  $cashflow    what is charged in the current calendar month
 * @return float
 */
function wallos_headline_monthly_cost($mode, $amortized, $shortCycles, $cashflow)
{
    switch (wallos_sanitize_monthly_cost_mode($mode)) {
        case 'short_cycles':
            return (float) $shortCycles;
        case 'cashflow':
            return (float) $cashflow;
    }

    return (float) $amortized;
}
