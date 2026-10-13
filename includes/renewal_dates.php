<?php
/*
  Renewal date arithmetic.

  DateTime::add(new DateInterval('P1M')) and strtotime('+1 month') roll the
  surplus days into the following month, so Jan 31 + 1 month is Mar 3 and a
  subscription billed on the 29th-31st drifts to the start of the month for
  good. These helpers clamp to the last day of the target month instead.

  Each occurrence is computed from the same base date (base + n steps) rather
  than by stepping from the previous result, so a run of renewals from Jan 31
  goes Feb 28, Mar 31, Apr 30 instead of settling on the 28th.
*/

/**
 * Adds a number of months to a date, clamping the day to the target month.
 *
 * @param DateTimeInterface $date
 * @param int               $months  may be negative
 * @return DateTime
 */
function wallos_add_months_clamped(DateTimeInterface $date, $months)
{
    $result = DateTime::createFromInterface($date);
    $monthIndex = (int) $date->format('Y') * 12 + (int) $date->format('n') - 1 + (int) $months;
    $year = intdiv($monthIndex, 12);
    $month = $monthIndex % 12 + 1;
    $lastDay = (int) (new DateTime(sprintf('%04d-%02d-01', $year, $month)))->format('t');
    $result->setDate($year, $month, min((int) $date->format('j'), $lastDay));

    return $result;
}

/**
 * Returns the date $steps renewals after (or before, when negative) $base.
 *
 * @param DateTimeInterface $base
 * @param string            $cycle      'Daily', 'Weekly', 'Monthly' or 'Yearly'
 * @param int               $frequency
 * @param int               $steps
 * @return DateTime
 */
function wallos_renewal_date(DateTimeInterface $base, $cycle, $frequency, $steps)
{
    $units = (int) $frequency * (int) $steps;

    switch ($cycle) {
        case 'Daily':
            return DateTime::createFromInterface($base)->modify(sprintf('%+d days', $units));
        case 'Weekly':
            return DateTime::createFromInterface($base)->modify(sprintf('%+d days', 7 * $units));
        case 'Yearly':
            return wallos_add_months_clamped($base, 12 * $units);
        case 'Monthly':
        default:
            return wallos_add_months_clamped($base, $units);
    }
}
