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
 * Price spread over one month: the same arithmetic as getPricePerMonth() in
 * the pages, for code that does not load them (the API).
 *
 * @return float
 */
function wallos_price_per_month($cycle, $frequency, $price)
{
    $frequency = max(1, (int) $frequency);
    switch ((int) $cycle) {
        case 1:
            return $price * (30 / $frequency);
        case 2:
            return $price * (4.35 / $frequency);
        case 3:
            return $price / $frequency;
        case 4:
            return $price / (12 * $frequency);
    }

    return 0.0;
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
