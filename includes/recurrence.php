<?php
/*
  One place that answers "when does this subscription bill?".

  The renewal cron, manual renewals, mark-as-paid, the calendar, the budget
  period, the list progress bar and fixed-term plans all ask the same
  questions, and used to answer them five different ways. They call these
  helpers instead.

  Calendar anchoring
    Monthly and yearly subscriptions bill on an anchor day (billing_anchor_day,
    or inferred from start_date / next_payment for rows saved before it
    existed). A month without that day clamps to its last day and the next
    month returns to the anchor: an anchor of 31 gives Jan 31, Feb 28, Mar 31,
    Apr 30. Stepping from one occurrence to the next therefore never drifts.

  Fixed-term plans
    end_date is the inclusive date of the final payment. total_payments is the
    plan length and payments_made counts payments that already happened.
    Nothing is projected after end_date.

  scripts/subscriptions.js carries a port of the clamping for the form's
  auto-fill button; tests/cases/recurrence_test.php pins both to the same
  vectors.
*/

require_once __DIR__ . '/renewal_dates.php';

const WALLOS_RECURRING_CYCLES = [1 => 'Daily', 2 => 'Weekly', 3 => 'Monthly', 4 => 'Yearly'];
const WALLOS_CYCLE_ONE_TIME = 5;
const WALLOS_RECURRENCE_MAX_STEPS = 10000;
const WALLOS_ALL_MONTHS = 4095;
const WALLOS_ACTIVE_MONTHS_MAX_SKIPS = 800;

/**
 * @param int|string $cycleId
 * @return string|null 'Daily', 'Weekly', 'Monthly', 'Yearly', or null for one-time and unknown cycles
 */
function wallos_cycle_name($cycleId)
{
    return WALLOS_RECURRING_CYCLES[(int) $cycleId] ?? null;
}

/**
 * Parses a stored Y-m-d date (anything after the first ten characters is ignored).
 *
 * @param mixed $value
 * @return DateTime|null midnight of that date
 */
function wallos_parse_date($value)
{
    if ($value instanceof DateTimeInterface) {
        return new DateTime($value->format('Y-m-d'));
    }
    if (!is_string($value) || strlen($value) < 10) {
        return null;
    }
    $date = DateTime::createFromFormat('!Y-m-d', substr($value, 0, 10));
    if ($date === false || $date->format('Y-m-d') !== substr($value, 0, 10)) {
        return null;
    }

    return $date;
}

/**
 * Moves a date to the anchor day of its own month, clamped to the month's last day.
 *
 * @param DateTimeInterface $date
 * @param int|null          $anchorDay
 * @return DateTime
 */
function wallos_clamp_to_anchor(DateTimeInterface $date, $anchorDay)
{
    $result = new DateTime($date->format('Y-m-d'));
    $anchorDay = (int) $anchorDay;
    if ($anchorDay < 1 || $anchorDay > 31) {
        return $result;
    }
    $result->setDate((int) $result->format('Y'), (int) $result->format('n'), min($anchorDay, (int) $result->format('t')));

    return $result;
}

/**
 * Works out the anchor day for a monthly or yearly subscription from its dates.
 *
 * Usually that is the day of next_payment. Two cases take the start day instead:
 * next_payment on the last day of a short month (start Jan 31, next Feb 28),
 * and a date the old month arithmetic rolled over (start Jan 31, next Mar 3).
 *
 * @param string|null $nextPayment
 * @param string|null $startDate
 * @param int|string  $cycleId
 * @return int|null null for cycles that do not anchor to a day of the month
 */
function wallos_infer_anchor_day($nextPayment, $startDate, $cycleId)
{
    if (!in_array((int) $cycleId, [3, 4], true)) {
        return null;
    }
    $next = wallos_parse_date($nextPayment);
    if ($next === null) {
        return null;
    }
    $anchor = (int) $next->format('j');
    $start = wallos_parse_date($startDate);
    if ($start !== null) {
        $startDay = (int) $start->format('j');
        $isMonthEnd = $anchor === (int) $next->format('t');
        $looksDrifted = $startDay >= 29 && $anchor <= 3;
        if ($startDay > $anchor && ($isMonthEnd || $looksDrifted)) {
            $anchor = $startDay;
        }
    }

    return $anchor;
}

/**
 * Picks the anchor to store when a subscription is saved: the existing one
 * when next_payment is still on it (Feb 28 under an anchor of 31), otherwise
 * one inferred from the new dates.
 *
 * @return int|null
 */
function wallos_anchor_day_for_save($nextPayment, $startDate, $cycleId, $existingAnchor = null)
{
    if (!in_array((int) $cycleId, [3, 4], true)) {
        return null;
    }
    $next = wallos_parse_date($nextPayment);
    $existingAnchor = (int) $existingAnchor;
    if ($next !== null && $existingAnchor >= 1 && $existingAnchor <= 31
        && wallos_clamp_to_anchor($next, $existingAnchor)->format('Y-m-d') === $next->format('Y-m-d')) {
        return $existingAnchor;
    }

    return wallos_infer_anchor_day($nextPayment, $startDate, $cycleId);
}

/**
 * @param array $subscription a subscriptions row
 * @return int|null
 */
function wallos_subscription_anchor_day(array $subscription)
{
    if (!in_array((int) ($subscription['cycle'] ?? 0), [3, 4], true)) {
        return null;
    }
    $stored = (int) ($subscription['billing_anchor_day'] ?? 0);
    if ($stored >= 1 && $stored <= 31) {
        return $stored;
    }

    return wallos_infer_anchor_day($subscription['next_payment'] ?? null, $subscription['start_date'] ?? null, $subscription['cycle']);
}

/**
 * Whether a cycle can be limited to some months: daily, weekly and monthly.
 *
 * @return bool
 */
function wallos_cycle_supports_active_months($cycleId)
{
    return in_array((int) $cycleId, [1, 2, 3], true);
}

/**
 * @param mixed $mask a 12-bit mask, bit 0 = January
 * @return int 1-4095; anything else is every month
 */
function wallos_sanitize_active_months($mask)
{
    if (!is_numeric($mask)) {
        return WALLOS_ALL_MONTHS;
    }
    $mask = (int) $mask;

    return $mask >= 1 && $mask <= WALLOS_ALL_MONTHS ? $mask : WALLOS_ALL_MONTHS;
}

/**
 * Builds the mask from month numbers.
 *
 * @param iterable $months 1-12 (January = 1)
 * @return int
 */
function wallos_active_months_from_list($months)
{
    $mask = 0;
    foreach ($months as $month) {
        $month = (int) $month;
        if ($month >= 1 && $month <= 12) {
            $mask |= 1 << ($month - 1);
        }
    }

    return $mask === 0 ? WALLOS_ALL_MONTHS : $mask;
}

/**
 * The months a subscription bills in. Yearly and one-time plans always bill
 * every month their schedule lands on.
 *
 * @return int
 */
function wallos_subscription_active_months(array $subscription)
{
    if (!wallos_cycle_supports_active_months($subscription['cycle'] ?? 0)) {
        return WALLOS_ALL_MONTHS;
    }

    return wallos_sanitize_active_months($subscription['active_months'] ?? WALLOS_ALL_MONTHS);
}

/**
 * @return bool
 */
function wallos_month_is_active($mask, DateTimeInterface $date)
{
    return ((int) $mask & (1 << ((int) $date->format('n') - 1))) !== 0;
}

/**
 * Moves a date forward to the first scheduled date in an active month (the
 * date itself when its month is active).
 *
 * @return DateTime|null
 */
function wallos_first_active_occurrence(array $subscription, DateTimeInterface $date)
{
    $date = new DateTime($date->format('Y-m-d'));
    $mask = wallos_subscription_active_months($subscription);
    if ($mask === WALLOS_ALL_MONTHS || wallos_month_is_active($mask, $date) || !wallos_is_recurring($subscription)) {
        return $date;
    }

    return wallos_step_occurrence($date, $subscription, 1);
}

/**
 * Short label of the active months: "Sep–Dec", "Jan, Mar–May", "Nov–Feb".
 *
 * @return string empty for every month
 */
function wallos_active_months_label($mask, $lang = 'en')
{
    $mask = wallos_sanitize_active_months($mask);
    if ($mask === WALLOS_ALL_MONTHS) {
        return '';
    }
    $names = [];
    for ($month = 1; $month <= 12; $month++) {
        $name = false;
        try {
            $formatter = new IntlDateFormatter($lang, IntlDateFormatter::NONE, IntlDateFormatter::NONE, null, null, 'LLL');
            $name = $formatter->format(new DateTime(sprintf('2001-%02d-01', $month)));
        } catch (Throwable $e) {
            $name = false;
        }
        $names[$month] = $name !== false ? $name : date('M', mktime(0, 0, 0, $month, 1, 2001));
    }

    $active = function ($month) use ($mask) {
        return ($mask & (1 << ((($month - 1) % 12 + 12) % 12))) !== 0;
    };
    // Start after an inactive month so a run across December stays whole.
    $start = 1;
    while ($active($start)) {
        $start++;
    }
    $ranges = [];
    for ($offset = 0; $offset < 12; $offset++) {
        $month = ($start + $offset - 1) % 12 + 1;
        if (!$active($month)) {
            continue;
        }
        $previous = ($month + 10) % 12 + 1;
        if ($offset > 0 && $active($previous) && $ranges !== []) {
            $ranges[count($ranges) - 1][1] = $month;
        } else {
            $ranges[] = [$month, $month];
        }
    }

    usort($ranges, function ($a, $b) {
        return $a[0] <=> $b[0];
    });

    return implode(', ', array_map(function ($range) use ($names) {
        return $range[0] === $range[1] ? $names[$range[0]] : $names[$range[0]] . '–' . $names[$range[1]];
    }, $ranges));
}

/**
 * Whether the subscription has a schedule to step along.
 *
 * @param array $subscription
 * @return bool
 */
function wallos_is_recurring(array $subscription)
{
    return wallos_cycle_name($subscription['cycle'] ?? 0) !== null && (int) ($subscription['frequency'] ?? 0) >= 1;
}

/**
 * Steps a date along a subscription's schedule, ignoring active months.
 *
 * @return DateTime|null
 */
function wallos_step_schedule(DateTimeInterface $date, array $subscription, $steps)
{
    $cycleName = wallos_cycle_name($subscription['cycle'] ?? 0);
    $frequency = (int) ($subscription['frequency'] ?? 0);
    if ($cycleName === null || $frequency < 1) {
        return null;
    }
    $result = wallos_renewal_date(new DateTime($date->format('Y-m-d')), $cycleName, $frequency, (int) $steps);
    $anchor = wallos_subscription_anchor_day($subscription);
    if ($anchor !== null) {
        $result = wallos_clamp_to_anchor($result, $anchor);
    }

    return $result;
}

/**
 * Steps a date along a subscription's schedule.
 *
 * With active months (issue #25), occurrences in an inactive month are
 * skipped: each step lands on the next date of the schedule that falls in an
 * active month.
 *
 * @param DateTimeInterface $date
 * @param array             $subscription needs cycle and frequency; billing_anchor_day
 *                                        and active_months are honoured
 * @param int               $steps        negative steps go backwards
 * @return DateTime|null null when the subscription does not recur
 */
function wallos_step_occurrence(DateTimeInterface $date, array $subscription, $steps = 1)
{
    $steps = (int) $steps;
    $mask = wallos_subscription_active_months($subscription);
    if ($mask === WALLOS_ALL_MONTHS || $steps === 0) {
        return wallos_step_schedule($date, $subscription, $steps);
    }

    $direction = $steps > 0 ? 1 : -1;
    $current = new DateTime($date->format('Y-m-d'));
    for ($remaining = abs($steps); $remaining > 0; $remaining--) {
        $candidate = $current;
        $found = false;
        for ($guard = 0; $guard < WALLOS_ACTIVE_MONTHS_MAX_SKIPS; $guard++) {
            $candidate = wallos_step_schedule($candidate, $subscription, $direction);
            if ($candidate === null) {
                return null;
            }
            if (wallos_month_is_active($mask, $candidate)) {
                $found = true;
                break;
            }
        }
        // A schedule that never reaches an active month (every 2 months from
        // January with only February active) bills as if every month were active.
        $current = $found ? $candidate : wallos_step_schedule($current, $subscription, $direction);
    }

    return $current;
}

/**
 * @return DateTime|null the occurrence after $from
 */
function wallos_next_occurrence(array $subscription, DateTimeInterface $from)
{
    return wallos_step_occurrence($from, $subscription, 1);
}

/**
 * @return DateTime|null the occurrence before $from
 */
function wallos_previous_occurrence(array $subscription, DateTimeInterface $from)
{
    return wallos_step_occurrence($from, $subscription, -1);
}

/**
 * @return DateTime|null the inclusive final payment date of a fixed-term plan
 */
function wallos_subscription_end_date(array $subscription)
{
    return wallos_parse_date($subscription['end_date'] ?? null);
}

/**
 * Every payment date of a subscription between two dates (inclusive).
 *
 * Occurrences are projected backwards and forwards from next_payment, never
 * before start_date and never after the plan's end_date.
 *
 * @param array             $subscription
 * @param DateTimeInterface $rangeStart
 * @param DateTimeInterface $rangeEnd
 * @param bool              $projectManual false: a manually renewed subscription only
 *                                         bills on its next_payment
 * @return DateTime[]
 */
function wallos_occurrences_in_range(array $subscription, DateTimeInterface $rangeStart, DateTimeInterface $rangeEnd, $projectManual = true)
{
    $next = wallos_parse_date($subscription['next_payment'] ?? null);
    if ($next === null) {
        return [];
    }
    $start = new DateTime($rangeStart->format('Y-m-d'));
    $end = new DateTime($rangeEnd->format('Y-m-d'));
    $planEnd = wallos_subscription_end_date($subscription);
    if ($planEnd !== null && $planEnd < $end) {
        $end = $planEnd;
    }
    $firstAllowed = wallos_parse_date($subscription['start_date'] ?? null);

    $inRange = function (DateTime $date) use ($start, $end, $firstAllowed) {
        return $date >= $start && $date <= $end && ($firstAllowed === null || $date >= $firstAllowed);
    };

    $autoRenew = !isset($subscription['auto_renew']) || (int) $subscription['auto_renew'] === 1;
    if (!wallos_is_recurring($subscription) || (!$autoRenew && !$projectManual)) {
        return $inRange($next) ? [$next] : [];
    }

    // next_payment is the seed and is always kept as it is stored: it may not
    // sit on the anchor (a date the old arithmetic rolled over, such as the
    // 3rd under an anchor of 31), and walking back and forth through the
    // anchored schedule would replace it. Earlier and later occurrences are
    // generated separately from it.
    $earlier = [];
    $current = wallos_previous_occurrence($subscription, $next);
    $guard = 0;
    while ($current >= $start && $guard++ < WALLOS_RECURRENCE_MAX_STEPS) {
        if ($firstAllowed !== null && $current < $firstAllowed) {
            break;
        }
        if ($current <= $end) {
            $earlier[] = $current;
        }
        $current = wallos_previous_occurrence($subscription, $current);
    }

    $occurrences = array_reverse($earlier);
    $current = $next;
    $guard = 0;
    while ($current <= $end && $guard++ < WALLOS_RECURRENCE_MAX_STEPS) {
        if ($inRange($current)) {
            $occurrences[] = $current;
        }
        $following = wallos_next_occurrence($subscription, $current);
        if ($following <= $current) {
            break;
        }
        $current = $following;
    }

    $mask = wallos_subscription_active_months($subscription);
    if ($mask !== WALLOS_ALL_MONTHS) {
        $occurrences = array_values(array_filter($occurrences, function (DateTime $date) use ($mask) {
            return wallos_month_is_active($mask, $date);
        }));
    }

    return $occurrences;
}

/**
 * Advances a subscription's next_payment past every date that has gone by.
 *
 * @param array             $subscription
 * @param DateTimeInterface $today
 * @param bool              $atLeastOne advance one cycle even when next_payment is not due yet
 * @return array{due_dates: string[], next_payment: string, ended: bool}
 *         due_dates are the payment dates that passed, oldest first; when the
 *         plan ended, next_payment is left on its final payment
 */
function wallos_advance_schedule(array $subscription, DateTimeInterface $today, $atLeastOne = false)
{
    $next = wallos_parse_date($subscription['next_payment'] ?? null);
    $today = new DateTime($today->format('Y-m-d'));
    $result = ['due_dates' => [], 'next_payment' => $next ? $next->format('Y-m-d') : null, 'ended' => false];
    if ($next === null || !wallos_is_recurring($subscription)) {
        return $result;
    }
    $planEnd = wallos_subscription_end_date($subscription);

    $cursor = $next;
    $guard = 0;
    while (($cursor < $today || ($atLeastOne && $result['due_dates'] === [])) && $guard++ < WALLOS_RECURRENCE_MAX_STEPS) {
        if ($planEnd !== null && $cursor > $planEnd) {
            break;
        }
        $result['due_dates'][] = $cursor->format('Y-m-d');
        $cursor = wallos_next_occurrence($subscription, $cursor);
    }

    if ($planEnd !== null && $cursor > $planEnd) {
        $result['ended'] = true;
        $last = end($result['due_dates']);
        $result['next_payment'] = $last !== false ? $last : $next->format('Y-m-d');
    } else {
        $result['next_payment'] = $cursor->format('Y-m-d');
    }

    return $result;
}

/**
 * Counts the scheduled payments in [$from, $to), stepping back from next_payment.
 *
 * @return int
 */
function wallos_count_occurrences_before_next(array $subscription, DateTimeInterface $from)
{
    $next = wallos_parse_date($subscription['next_payment'] ?? null);
    if ($next === null || !wallos_is_recurring($subscription)) {
        return 0;
    }
    $from = new DateTime($from->format('Y-m-d'));
    $count = 0;
    $current = wallos_previous_occurrence($subscription, $next);
    while ($current >= $from && $count < WALLOS_RECURRENCE_MAX_STEPS) {
        $count++;
        $current = wallos_previous_occurrence($subscription, $current);
    }

    return $count;
}

/**
 * Fills in the fixed-term plan fields when a subscription is saved.
 *
 * The form edits either the end date or the number of payments; the other is
 * derived so both are always stored, and plan_mode records which one the
 * user chose (a count-based plan keeps its count when a manual payment
 * restarts the cycle; a date-based plan keeps its date). payments_made is
 * counted from the start date when there is one.
 *
 * A completed plan keeps its stored payments_made: its next_payment stays on
 * the final payment, which was already made, so counting the dates before it
 * would lose that payment on every save. So does a row with
 * keep_payments_made set (the caller passes it when the schedule did not
 * change, or when payments restart the cycle and the history is not on a
 * regular schedule that could be counted).
 *
 * @param array  $subscription row with next_payment, start_date, cycle, frequency,
 *                             billing_anchor_day, payments_made, ended_reason
 * @param string $mode         'never', 'date' or 'count'
 * @param mixed  $endDate
 * @param mixed  $totalPayments
 * @return array{end_date: ?string, total_payments: ?int, payments_made: int, plan_mode: ?string}
 */
function wallos_plan_fields(array $subscription, $mode, $endDate, $totalPayments)
{
    $paymentsMade = max(0, (int) ($subscription['payments_made'] ?? 0));
    $completed = ($subscription['ended_reason'] ?? null) === 'completed';
    $start = wallos_parse_date($subscription['start_date'] ?? null);
    $next = wallos_parse_date($subscription['next_payment'] ?? null);
    $keepCount = $completed || !empty($subscription['keep_payments_made']);
    if (!$keepCount && $start !== null && $next !== null && wallos_is_recurring($subscription)) {
        $paymentsMade = wallos_count_occurrences_before_next($subscription, $start);
    }
    // Payments made before next_payment; for a completed plan next_payment
    // itself was the final one.
    $madeBeforeNext = $completed ? max(0, $paymentsMade - 1) : $paymentsMade;

    $none = ['end_date' => null, 'total_payments' => null, 'payments_made' => $paymentsMade, 'plan_mode' => null];
    if ($next === null || !wallos_is_recurring($subscription)) {
        return $none;
    }

    if ($mode === 'count') {
        $total = (int) $totalPayments;
        if ($total < 1) {
            return $none;
        }
        // The final payment is $remaining - 1 steps after next_payment; a plan
        // that is already paid off ended on a payment before next_payment.
        $remaining = $total - $madeBeforeNext;
        $final = wallos_step_occurrence($next, $subscription, $remaining - 1);

        return ['end_date' => $final->format('Y-m-d'), 'total_payments' => $total, 'payments_made' => $paymentsMade, 'plan_mode' => 'count'];
    }

    if ($mode === 'date') {
        $final = wallos_parse_date($endDate);
        if ($final === null) {
            return $none;
        }
        $upcoming = 0;
        $current = $next;
        while ($current <= $final && $upcoming < WALLOS_RECURRENCE_MAX_STEPS) {
            $upcoming++;
            $current = wallos_next_occurrence($subscription, $current);
        }

        return ['end_date' => $final->format('Y-m-d'), 'total_payments' => $madeBeforeNext + $upcoming,
            'payments_made' => $paymentsMade, 'plan_mode' => 'date'];
    }

    return $none;
}

/**
 * How far today is through the current billing cycle, in percent.
 *
 * @return int 0-100
 */
function wallos_cycle_progress(array $subscription, DateTimeInterface $today)
{
    $next = wallos_parse_date($subscription['next_payment'] ?? null);
    if ($next === null || !wallos_is_recurring($subscription)) {
        return 0;
    }
    $today = new DateTime($today->format('Y-m-d'));

    // next_payment can be stale or far ahead: measure against the cycle that contains today.
    $guard = 0;
    while ($next <= $today && $guard++ < WALLOS_RECURRENCE_MAX_STEPS) {
        $next = wallos_next_occurrence($subscription, $next);
    }
    $previous = wallos_previous_occurrence($subscription, $next);
    while ($previous > $today && $guard++ < WALLOS_RECURRENCE_MAX_STEPS) {
        $next = $previous;
        $previous = wallos_previous_occurrence($subscription, $next);
    }

    $cycleDays = (int) $previous->diff($next)->days;
    if ($cycleDays <= 0) {
        return 0;
    }

    return (int) max(0, min(100, floor($previous->diff($today)->days / $cycleDays * 100)));
}
