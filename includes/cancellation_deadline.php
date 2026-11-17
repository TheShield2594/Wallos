<?php
/*
  Cancellation notice: when does a subscription have to be cancelled by?

  A subscription has one of three modes (subscriptions.cancel_notice_mode):

    none            no reminder.
    fixed           a reminder on one date, cancellation_date (the original
                    behaviour, and what every row saved before this existed
                    with a cancellation date is).
    before_renewal  N days, weeks or months before each renewal
                    (cancel_notice_value, cancel_notice_unit). The cancel-by
                    date is computed from next_payment, so when the renewal
                    cron moves next_payment on, the deadline moves with it.

  min_term_end is the end of a minimum term: renewals before it cannot be
  avoided, so the deadline is the one for the first renewal on or after it,
  and the subscription reads "Locked in" until then.

  last_cancel_notified_for is the cancel-by date the last reminder was sent
  for. A reminder is due on any day from its cancel-by date until the renewal
  it is about (or, for a fixed date, for a week), once, so a day the cron did
  not run no longer loses it.

  The dashboard card, the statistics page, the list, the details popup and
  the notification cron all read the dates from here.
*/

require_once __DIR__ . '/recurrence.php';

const WALLOS_CANCEL_MODES = ['none', 'fixed', 'before_renewal'];
const WALLOS_CANCEL_UNITS = ['days', 'weeks', 'months'];
const WALLOS_CANCEL_FIXED_GRACE_DAYS = 7;

/**
 * SQL condition, true when wallos_cancel_mode() is not 'none': a valid
 * before-renewal notice, or a valid date under any mode but 'none'. A date
 * counts only when it round-trips as Y-m-d, as in wallos_parse_date() (the
 * '+0 days' modifier makes every SQLite version normalize Feb 30), so a
 * malformed legacy date is "none" in SQL filters and in PHP alike. Wrapped in
 * COALESCE so it is never NULL, and NOT of it is the exact complement.
 */
const WALLOS_CANCEL_NOTICE_SQL = "COALESCE(((cancel_notice_mode = 'before_renewal' AND cancel_notice_unit IN ('days', 'weeks', 'months')
        AND cancel_notice_value IS NOT NULL AND cancel_notice_value BETWEEN 0 AND 3650 AND cancel_notice_value = CAST(cancel_notice_value AS INTEGER))
    OR (COALESCE(cancel_notice_mode, '') != 'none' AND cancellation_date IS NOT NULL
        AND date(substr(cancellation_date, 1, 10), '+0 days') = substr(cancellation_date, 1, 10))), 0)";

/**
 * @param array $subscription a subscriptions row
 * @return string 'none', 'fixed' or 'before_renewal'
 */
function wallos_cancel_mode(array $subscription)
{
    $mode = $subscription['cancel_notice_mode'] ?? null;
    if ($mode === 'before_renewal' && wallos_cancel_notice_valid($subscription['cancel_notice_value'] ?? null, $subscription['cancel_notice_unit'] ?? null)) {
        return 'before_renewal';
    }
    if ($mode === 'none') {
        return 'none';
    }
    // 'fixed', or a row saved before the mode existed.
    return wallos_parse_date($subscription['cancellation_date'] ?? null) !== null ? 'fixed' : 'none';
}

/**
 * @return bool whether value/unit make a usable notice period
 */
function wallos_cancel_notice_valid($value, $unit)
{
    return in_array($unit, WALLOS_CANCEL_UNITS, true)
        && is_numeric($value) && (int) $value == $value && (int) $value >= 0 && (int) $value <= 3650;
}

/**
 * Moves a date back by a notice period. Months clamp to the end of a shorter
 * month (Mar 31 - 1 month = Feb 28).
 *
 * @return DateTime
 */
function wallos_subtract_notice(DateTimeInterface $date, $value, $unit)
{
    $value = (int) $value;
    switch ($unit) {
        case 'months':
            return wallos_add_months_clamped(new DateTime($date->format('Y-m-d')), -$value);
        case 'weeks':
            return (new DateTime($date->format('Y-m-d')))->modify(sprintf('-%d days', 7 * $value));
        default:
            return (new DateTime($date->format('Y-m-d')))->modify(sprintf('-%d days', $value));
    }
}

/**
 * The renewal a before-renewal notice is about: next_payment, or the first
 * renewal on or after the end of the minimum term. Null when nothing renews
 * (a one-time payment, or a fixed-term plan that ends first).
 *
 * @return DateTime|null
 */
function wallos_cancel_target_renewal(array $subscription)
{
    $next = wallos_parse_date($subscription['next_payment'] ?? null);
    if ($next === null || !wallos_is_recurring($subscription)) {
        return null;
    }
    $minTermEnd = wallos_parse_date($subscription['min_term_end'] ?? null);
    $renewal = $next;
    $guard = 0;
    while ($minTermEnd !== null && $renewal < $minTermEnd && $guard++ < WALLOS_RECURRENCE_MAX_STEPS) {
        $following = wallos_next_occurrence($subscription, $renewal);
        if ($following === null || $following <= $renewal) {
            return null;
        }
        $renewal = $following;
    }
    $planEnd = wallos_subscription_end_date($subscription);
    if ($planEnd !== null && $renewal > $planEnd) {
        return null;
    }

    return $renewal;
}

/**
 * Cancel-by date of a subscription, or null when it has none: no notice set,
 * disabled, or a one-time payment.
 *
 * @return array|null ['cancel_by' => Y-m-d, 'renewal' => Y-m-d|null, 'mode' => string]
 */
function wallos_cancel_deadline(array $subscription)
{
    if ((int) ($subscription['inactive'] ?? 0) === 1 || (int) ($subscription['cycle'] ?? 0) === WALLOS_CYCLE_ONE_TIME) {
        return null;
    }
    $mode = wallos_cancel_mode($subscription);
    if ($mode === 'fixed') {
        return ['cancel_by' => wallos_parse_date($subscription['cancellation_date'])->format('Y-m-d'), 'renewal' => null, 'mode' => 'fixed'];
    }
    if ($mode !== 'before_renewal') {
        return null;
    }
    $renewal = wallos_cancel_target_renewal($subscription);
    if ($renewal === null) {
        return null;
    }
    $cancelBy = wallos_subtract_notice($renewal, $subscription['cancel_notice_value'], $subscription['cancel_notice_unit']);

    return ['cancel_by' => $cancelBy->format('Y-m-d'), 'renewal' => $renewal->format('Y-m-d'), 'mode' => 'before_renewal'];
}

/**
 * The end of the minimum term while the next renewal still falls inside it.
 *
 * @return string|null Y-m-d
 */
function wallos_cancel_locked_until(array $subscription)
{
    $minTermEnd = wallos_parse_date($subscription['min_term_end'] ?? null);
    $next = wallos_parse_date($subscription['next_payment'] ?? null);
    if ($minTermEnd === null || $next === null || wallos_cancel_mode($subscription) !== 'before_renewal') {
        return null;
    }

    return $next < $minTermEnd ? $minTermEnd->format('Y-m-d') : null;
}

/**
 * Whether the cancellation reminder is due on this cron run.
 *
 * Due on any day from the cancel-by date while the renewal it is about has
 * not happened yet (a fixed date: for a week), and not again once sent.
 *
 * @param string $today Y-m-d
 * @return array|null the deadline when due
 */
function wallos_cancellation_due(array $subscription, $today)
{
    $deadline = wallos_cancel_deadline($subscription);
    if ($deadline === null || $deadline['cancel_by'] > $today) {
        return null;
    }
    if (($subscription['last_cancel_notified_for'] ?? null) === $deadline['cancel_by']) {
        return null;
    }
    if ($deadline['renewal'] !== null) {
        // Until the renewal; a notice of zero days is due on the renewal day itself.
        if ($deadline['renewal'] < $today || ($deadline['renewal'] === $today && $deadline['cancel_by'] !== $today)) {
            return null;
        }
    } else {
        $graceEnd = (new DateTime($deadline['cancel_by']))->modify('+' . WALLOS_CANCEL_FIXED_GRACE_DAYS . ' days')->format('Y-m-d');
        if ($today > $graceEnd) {
            return null;
        }
    }

    return $deadline;
}

/**
 * Key of one reminder in a notification run: what it is about ('cancel' or
 * 'intro') and which subscription.
 */
function wallos_notification_key($type, $subscriptionId)
{
    return $type . ':' . (int) $subscriptionId;
}

/**
 * Records the reminders a channel delivered (each entry carries its 'key').
 */
function wallos_notifications_delivered(array &$delivered, array $entries)
{
    foreach ($entries as $entry) {
        if (isset($entry['key'])) {
            $delivered[$entry['key']] = true;
        }
    }
}

/**
 * The "sent" markers to store: only for reminders at least one channel
 * delivered, so the others are tried again on the next run.
 *
 * @param array  $reminded  subscription id => marker
 * @param array  $delivered key => true
 * @param string $type      'cancel' or 'intro'
 * @return array subscription id => marker
 */
function wallos_delivered_markers(array $reminded, array $delivered, $type)
{
    $markers = [];
    foreach ($reminded as $subscriptionId => $marker) {
        if (!empty($delivered[wallos_notification_key($type, $subscriptionId)])) {
            $markers[$subscriptionId] = $marker;
        }
    }

    return $markers;
}

/**
 * Whether a notification service accepted a request: a 2xx answer, and for
 * Telegram also "ok": true in the body (the Bot API reports failures there).
 *
 * @param int         $httpCode
 * @param string|bool $body     the response, false when the request failed
 * @param bool        $telegram
 */
function wallos_notification_http_delivered($httpCode, $body, $telegram = false)
{
    if ($body === false || (int) $httpCode < 200 || (int) $httpCode >= 300) {
        return false;
    }
    if ($telegram) {
        $decoded = json_decode((string) $body, true);

        return is_array($decoded) && ($decoded['ok'] ?? false) === true;
    }

    return true;
}

/**
 * Validates the cancellation fields of a save request.
 *
 * @param array $input  mode, cancellation_date, notice value/unit, min_term_end
 * @param int   $cycle
 * @return array{error: string|null, fields: array} fields are the columns to store
 */
function wallos_cancel_fields_from_input(array $input, $cycle)
{
    $mode = $input['cancel_notice_mode'] ?? null;
    if ($mode === null || $mode === '') {
        // Older clients only send a date.
        $mode = wallos_parse_date($input['cancellation_date'] ?? null) !== null ? 'fixed' : 'none';
    }
    if (!in_array($mode, WALLOS_CANCEL_MODES, true)) {
        return ['error' => 'Invalid cancellation notice.', 'fields' => []];
    }
    if ((int) $cycle === WALLOS_CYCLE_ONE_TIME) {
        $mode = 'none';
    }

    $fields = [
        'cancel_notice_mode' => $mode,
        'cancellation_date' => null,
        'cancel_notice_value' => null,
        'cancel_notice_unit' => null,
        'min_term_end' => null,
    ];

    if ($mode === 'fixed') {
        $date = wallos_parse_date($input['cancellation_date'] ?? null);
        if ($date === null) {
            return ['error' => 'Invalid cancellation date.', 'fields' => []];
        }
        $fields['cancellation_date'] = $date->format('Y-m-d');
    } elseif ($mode === 'before_renewal') {
        $value = $input['cancel_notice_value'] ?? null;
        $unit = $input['cancel_notice_unit'] ?? 'days';
        if (!wallos_cancel_notice_valid($value, $unit)) {
            return ['error' => 'Invalid cancellation notice period.', 'fields' => []];
        }
        $fields['cancel_notice_value'] = (int) $value;
        $fields['cancel_notice_unit'] = $unit;
        $minTermEnd = $input['min_term_end'] ?? '';
        if ($minTermEnd !== '' && $minTermEnd !== null) {
            $parsed = wallos_parse_date($minTermEnd);
            if ($parsed === null) {
                return ['error' => 'Invalid minimum term date.', 'fields' => []];
            }
            $fields['min_term_end'] = $parsed->format('Y-m-d');
        }
    }

    return ['error' => null, 'fields' => $fields];
}

/**
 * Stores cancellation fields on a subscription.
 */
function wallos_store_cancel_fields(SQLite3 $db, $subscriptionId, $userId, array $fields)
{
    $stmt = $db->prepare('UPDATE subscriptions SET cancel_notice_mode = :mode, cancellation_date = :date,
        cancel_notice_value = :value, cancel_notice_unit = :unit, min_term_end = :minTermEnd
        WHERE id = :id AND user_id = :userId');
    $stmt->bindValue(':mode', $fields['cancel_notice_mode'], SQLITE3_TEXT);
    $stmt->bindValue(':date', $fields['cancellation_date'], $fields['cancellation_date'] === null ? SQLITE3_NULL : SQLITE3_TEXT);
    $stmt->bindValue(':value', $fields['cancel_notice_value'], $fields['cancel_notice_value'] === null ? SQLITE3_NULL : SQLITE3_INTEGER);
    $stmt->bindValue(':unit', $fields['cancel_notice_unit'], $fields['cancel_notice_unit'] === null ? SQLITE3_NULL : SQLITE3_TEXT);
    $stmt->bindValue(':minTermEnd', $fields['min_term_end'], $fields['min_term_end'] === null ? SQLITE3_NULL : SQLITE3_TEXT);
    $stmt->bindValue(':id', (int) $subscriptionId, SQLITE3_INTEGER);
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);

    return $stmt->execute() !== false;
}
