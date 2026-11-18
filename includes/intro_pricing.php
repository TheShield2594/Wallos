<?php
/*
  Free trials and introductory prices.

  subscriptions
    intro_type         'trial' (free) or 'promo' (a reduced price), NULL when
                       the subscription has no intro period (or it has ended).
    intro_price        The promo price per payment (0 for a trial).
    intro_end_date     The first payment at the regular price. Payments before
                       it are free (trial) or at intro_price (promo). For a
                       trial it is also next_payment: the first paid date.
    intro_notify_days  Days before intro_end_date to send the "trial ending"
                       reminder.
    intro_notified_for The intro_end_date the "ending" reminder was sent for,
                       or 'converted:<date>' once the conversion was announced.
    intro_converted_on The day the renewal cron saw the intro period end and
                       the regular price take over (notified once). The intro
                       columns are kept: prices are decided per payment date,
                       so earlier payments keep their intro price for a retry
                       of a missed cycle or an undone payment.

  While an intro period runs:
    - the price of a payment is wallos_price_on() (intro price before
      intro_end_date, the regular price from it on), used by the monthly
      cost, the budget and the payment history;
    - payment reminders for payments up to intro_end_date are suppressed: the
      "trial ending" reminder replaces them;
    - disabling the subscription before intro_end_date is a cancelled trial,
      counted as a saving at the regular price.
*/

require_once __DIR__ . '/recurrence.php';

const WALLOS_INTRO_TYPES = ['trial', 'promo'];

/**
 * @return bool whether the subscription has an intro period set
 */
function wallos_has_intro(array $subscription)
{
    return in_array($subscription['intro_type'] ?? null, WALLOS_INTRO_TYPES, true)
        && wallos_parse_date($subscription['intro_end_date'] ?? null) !== null;
}

/**
 * Whether the intro period still runs on $date.
 *
 * @param DateTimeInterface|string|null $date defaults to today
 */
function wallos_intro_active(array $subscription, $date = null)
{
    if (!wallos_has_intro($subscription)) {
        return false;
    }
    $day = $date === null ? new DateTime('today') : wallos_parse_date($date);

    return $day !== null && $day < wallos_parse_date($subscription['intro_end_date']);
}

/**
 * The intro price per payment: 0 for a trial.
 *
 * @return float
 */
function wallos_intro_price(array $subscription)
{
    return ($subscription['intro_type'] ?? null) === 'trial' ? 0.0 : (float) ($subscription['intro_price'] ?? 0);
}

/**
 * What a payment on $date costs: the intro price before intro_end_date,
 * the regular price from it on.
 *
 * @param DateTimeInterface|string $date
 * @return float
 */
function wallos_price_on(array $subscription, $date)
{
    return wallos_intro_active($subscription, $date) ? wallos_intro_price($subscription) : (float) $subscription['price'];
}

/**
 * The price that applies today, for the monthly cost and the list.
 *
 * @return float
 */
function wallos_current_price(array $subscription, ?DateTimeInterface $today = null)
{
    return wallos_price_on($subscription, $today ?? new DateTime('today'));
}

/**
 * Sum of the prices of a list of payment dates.
 *
 * @param DateTimeInterface[] $dates
 * @return float
 */
function wallos_price_of_occurrences(array $subscription, array $dates)
{
    $total = 0.0;
    foreach ($dates as $date) {
        $total += wallos_price_on($subscription, $date);
    }

    return $total;
}

/**
 * Whether the payment reminder for next_payment is replaced by the intro
 * reminder: any payment up to the end of the intro period.
 */
function wallos_intro_suppresses_payment_reminder(array $subscription)
{
    if (!wallos_has_intro($subscription)) {
        return false;
    }
    $next = wallos_parse_date($subscription['next_payment'] ?? null);

    return $next !== null && $next <= wallos_parse_date($subscription['intro_end_date']);
}

/**
 * Whether the "trial ending" reminder is due on this cron run: from
 * intro_notify_days before intro_end_date until it, once.
 *
 * @param string $today Y-m-d
 * @return bool
 */
function wallos_intro_ending_due(array $subscription, $today)
{
    if ((int) ($subscription['inactive'] ?? 0) === 1 || !wallos_intro_active($subscription, $today)) {
        return false;
    }
    $end = wallos_parse_date($subscription['intro_end_date']);
    $days = max(0, (int) ($subscription['intro_notify_days'] ?? 3));
    $remindFrom = (clone $end)->modify("-$days days")->format('Y-m-d');
    if ($today < $remindFrom) {
        return false;
    }

    return ($subscription['intro_notified_for'] ?? null) !== $end->format('Y-m-d');
}

/**
 * Whether the "trial converted" notice is due: once, within a week of the
 * conversion.
 *
 * @param string $today Y-m-d
 */
function wallos_intro_converted_due(array $subscription, $today)
{
    $converted = wallos_parse_date($subscription['intro_converted_on'] ?? null);
    if ($converted === null || (int) ($subscription['inactive'] ?? 0) === 1) {
        return false;
    }
    if ($today > (clone $converted)->modify('+7 days')->format('Y-m-d')) {
        return false;
    }

    return ($subscription['intro_notified_for'] ?? null) !== 'converted:' . $converted->format('Y-m-d');
}

/**
 * Records the end of every intro period that has run its course, for the
 * one-off "converted" notice. The intro columns stay: wallos_price_on()
 * prices each payment by its date, so the regular price applies from the end
 * date on, while a cycle the cron could not record yet, or a payment undone
 * later, still gets the intro price it was due at. Disabled subscriptions
 * are left alone (a cancelled trial).
 *
 * @param string   $today  Y-m-d
 * @param int|null $userId only this account's (the renewal cron passes each
 *                         account's local date); null for every account
 * @return int how many were converted
 */
function wallos_convert_ended_intros(SQLite3 $db, $today, $userId = null)
{
    $stmt = $db->prepare("UPDATE subscriptions SET intro_converted_on = :today
        WHERE intro_type IS NOT NULL AND intro_end_date IS NOT NULL AND intro_end_date <= :today
          AND inactive = 0 AND intro_converted_on IS NULL"
        . ($userId === null ? '' : ' AND user_id = :userId'));
    $stmt->bindValue(':today', $today, SQLITE3_TEXT);
    if ($userId !== null) {
        $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
    }
    $stmt->execute();

    return $db->changes();
}

/**
 * Validates the intro fields of a save request and works out the end date.
 *
 * @param array $input intro_type ('none'|'trial'|'promo'), intro_length_mode
 *                     ('cycles'|'date'), intro_cycles, intro_end_date,
 *                     intro_price, intro_notify_days
 * @param array $schedule cycle, frequency, start_date, next_payment, billing_anchor_day
 * @return array{error: string|null, fields: array, next_payment: string|null}
 *         next_payment: the first paid date a trial moves next_payment to
 */
function wallos_intro_fields_from_input(array $input, array $schedule)
{
    $empty = ['intro_type' => null, 'intro_price' => null, 'intro_end_date' => null, 'intro_notify_days' => 3];
    $type = $input['intro_type'] ?? 'none';
    if ($type === '' || $type === null || $type === 'none' || (int) ($schedule['cycle'] ?? 0) === WALLOS_CYCLE_ONE_TIME) {
        return ['error' => null, 'fields' => $empty, 'next_payment' => null];
    }
    if (!in_array($type, WALLOS_INTRO_TYPES, true)) {
        return ['error' => 'Invalid intro period.', 'fields' => [], 'next_payment' => null];
    }

    $mode = ($input['intro_length_mode'] ?? 'date') === 'cycles' ? 'cycles' : 'date';
    if ($mode === 'cycles') {
        $cycles = $input['intro_cycles'] ?? null;
        if (!is_numeric($cycles) || (int) $cycles != $cycles || (int) $cycles < 1 || (int) $cycles > 1000) {
            return ['error' => 'Invalid intro length.', 'fields' => [], 'next_payment' => null];
        }
        // N cycles from the start (or, without one, from the next payment).
        $from = wallos_parse_date($schedule['start_date'] ?? null) ?? wallos_parse_date($schedule['next_payment'] ?? null);
        $anchored = array_merge($schedule, [
            'billing_anchor_day' => $from !== null && in_array((int) ($schedule['cycle'] ?? 0), [3, 4], true) ? (int) $from->format('j') : null,
        ]);
        $end = $from === null ? null : wallos_step_occurrence($from, $anchored, (int) $cycles);
    } else {
        $end = wallos_parse_date($input['intro_end_date'] ?? null);
    }
    if ($end === null) {
        return ['error' => 'Invalid intro end date.', 'fields' => [], 'next_payment' => null];
    }

    $price = null;
    if ($type === 'promo') {
        $price = $input['intro_price'] ?? null;
        if (!is_numeric($price) || (float) $price < 0) {
            return ['error' => 'Invalid intro price.', 'fields' => [], 'next_payment' => null];
        }
        $price = (float) $price;
    }

    $notifyDays = $input['intro_notify_days'] ?? 3;
    // At least a day: on the end date the regular price already applies.
    if (!is_numeric($notifyDays) || (int) $notifyDays < 1 || (int) $notifyDays > 90) {
        return ['error' => 'Invalid intro reminder.', 'fields' => [], 'next_payment' => null];
    }

    return [
        'error' => null,
        'fields' => [
            'intro_type' => $type,
            'intro_price' => $price,
            'intro_end_date' => $end->format('Y-m-d'),
            'intro_notify_days' => (int) $notifyDays,
        ],
        // A trial's first payment is the first paid one (while it is ahead).
        'next_payment' => $type === 'trial' && $end >= new DateTime('today') ? $end->format('Y-m-d') : null,
    ];
}

/**
 * Whether a disabled subscription is a cancelled trial: disabled with an
 * intro period that never converted to the regular price.
 */
function wallos_is_cancelled_trial(array $subscription)
{
    return (int) ($subscription['inactive'] ?? 0) === 1 && wallos_has_intro($subscription)
        && empty($subscription['intro_converted_on']);
}

/**
 * Stores intro fields on a subscription. A changed end date can be reminded
 * again; the converted marker is cleared when the intro period changes.
 */
function wallos_store_intro_fields(SQLite3 $db, $subscriptionId, $userId, array $fields)
{
    $stmt = $db->prepare('UPDATE subscriptions SET intro_type = :type, intro_price = :price, intro_end_date = :endDate,
            intro_notify_days = :notifyDays,
            intro_converted_on = CASE WHEN :type IS NOT NULL AND intro_end_date IS NOT :endDate THEN NULL ELSE intro_converted_on END
        WHERE id = :id AND user_id = :userId');
    $stmt->bindValue(':type', $fields['intro_type'], $fields['intro_type'] === null ? SQLITE3_NULL : SQLITE3_TEXT);
    $stmt->bindValue(':price', $fields['intro_price'], $fields['intro_price'] === null ? SQLITE3_NULL : SQLITE3_FLOAT);
    $stmt->bindValue(':endDate', $fields['intro_end_date'], $fields['intro_end_date'] === null ? SQLITE3_NULL : SQLITE3_TEXT);
    $stmt->bindValue(':notifyDays', (int) $fields['intro_notify_days'], SQLITE3_INTEGER);
    $stmt->bindValue(':id', (int) $subscriptionId, SQLITE3_INTEGER);
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);

    return $stmt->execute() !== false;
}
