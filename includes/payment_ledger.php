<?php
/*
  Payment ledger: what was actually paid for each billing cycle.

  subscription_payments holds one row per charge, keyed by (subscription_id,
  due_date). Rows come from three places:

    - the renewal cron, when it moves an auto-renewing subscription past a due
      date: status 'assumed' with the list price, or 'pending_amount' with no
      amount for a variable-amount subscription;
    - "Mark paid": status 'paid' with today's date;
    - the History tab, which edits or deletes rows.

  The cron inserts with INSERT OR IGNORE, so a cycle the user already marked
  as paid early is never overwritten and a re-run never duplicates a row.

  Statuses
    paid            confirmed by the user
    assumed         advanced by the cron at the list price, not confirmed
    pending_amount  advanced by the cron, the amount still has to be entered
*/

require_once __DIR__ . '/recurrence.php';
require_once __DIR__ . '/currency_rates.php';

const WALLOS_LEDGER_STATUSES = ['paid', 'assumed', 'pending_amount'];

/**
 * @return array|false the subscription row owned by $userId
 */
function wallos_ledger_load_subscription(SQLite3 $db, $userId, $subscriptionId)
{
    $stmt = $db->prepare('SELECT * FROM subscriptions WHERE id = :id AND user_id = :userId');
    $stmt->bindValue(':id', (int) $subscriptionId, SQLITE3_INTEGER);
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    return $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
}

/**
 * Records cycles the cron moved past, without touching rows that already exist.
 *
 * @param string[] $dueDates
 */
function wallos_ledger_record_cycles(SQLite3 $db, array $subscription, array $dueDates)
{
    if ($dueDates === []) {
        return;
    }
    $variable = (int) ($subscription['variable_amount'] ?? 0) === 1;
    $stmt = $db->prepare("INSERT OR IGNORE INTO subscription_payments
        (subscription_id, user_id, due_date, paid_date, amount, currency_id, status, source)
        VALUES (:subscriptionId, :userId, :dueDate, :paidDate, :amount, :currencyId, :status, 'auto')");
    foreach ($dueDates as $dueDate) {
        $stmt->bindValue(':subscriptionId', (int) $subscription['id'], SQLITE3_INTEGER);
        $stmt->bindValue(':userId', (int) $subscription['user_id'], SQLITE3_INTEGER);
        $stmt->bindValue(':dueDate', $dueDate, SQLITE3_TEXT);
        $stmt->bindValue(':paidDate', $variable ? null : $dueDate, $variable ? SQLITE3_NULL : SQLITE3_TEXT);
        $stmt->bindValue(':amount', $variable ? null : (float) $subscription['price'], $variable ? SQLITE3_NULL : SQLITE3_FLOAT);
        $stmt->bindValue(':currencyId', (int) $subscription['currency_id'], SQLITE3_INTEGER);
        $stmt->bindValue(':status', $variable ? 'pending_amount' : 'assumed', SQLITE3_TEXT);
        $stmt->execute();
        $stmt->reset();
    }
}

/**
 * Writes a confirmed payment, replacing whatever the row for that cycle held.
 */
function wallos_ledger_upsert_paid(SQLite3 $db, array $subscription, $dueDate, $paidDate, $amount, $source)
{
    $stmt = $db->prepare("INSERT INTO subscription_payments
        (subscription_id, user_id, due_date, paid_date, amount, currency_id, status, source)
        VALUES (:subscriptionId, :userId, :dueDate, :paidDate, :amount, :currencyId, 'paid', :source)
        ON CONFLICT(subscription_id, due_date) DO UPDATE SET
            paid_date = excluded.paid_date, amount = excluded.amount, status = 'paid', source = excluded.source");
    $stmt->bindValue(':subscriptionId', (int) $subscription['id'], SQLITE3_INTEGER);
    $stmt->bindValue(':userId', (int) $subscription['user_id'], SQLITE3_INTEGER);
    $stmt->bindValue(':dueDate', $dueDate, SQLITE3_TEXT);
    $stmt->bindValue(':paidDate', $paidDate, SQLITE3_TEXT);
    $stmt->bindValue(':amount', (float) $amount, SQLITE3_FLOAT);
    $stmt->bindValue(':currencyId', (int) $subscription['currency_id'], SQLITE3_INTEGER);
    $stmt->bindValue(':source', $source, SQLITE3_TEXT);

    return $stmt->execute() !== false;
}

/**
 * Updates the schedule columns of a subscription.
 *
 * @param array $fields column => value, limited to the schedule columns
 */
function wallos_update_schedule(SQLite3 $db, array $subscription, array $fields)
{
    $allowed = ['next_payment', 'payments_made', 'inactive', 'ended_reason', 'billing_anchor_day'];
    $sets = [];
    foreach (array_keys($fields) as $column) {
        if (!in_array($column, $allowed, true)) {
            throw new InvalidArgumentException('Unknown schedule column ' . $column);
        }
        $sets[] = "$column = :$column";
    }
    if ($sets === []) {
        return true;
    }
    $stmt = $db->prepare('UPDATE subscriptions SET ' . implode(', ', $sets) . ' WHERE id = :id AND user_id = :userId');
    foreach ($fields as $column => $value) {
        $stmt->bindValue(':' . $column, $value, $value === null ? SQLITE3_NULL : (is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT));
    }
    $stmt->bindValue(':id', (int) $subscription['id'], SQLITE3_INTEGER);
    $stmt->bindValue(':userId', (int) $subscription['user_id'], SQLITE3_INTEGER);

    return $stmt->execute() !== false;
}

/**
 * Moves an auto-renewing subscription past every due date that has gone by:
 * one ledger row per cycle, payments_made counted, and a fixed-term plan
 * closed once its final payment has passed. Used by the renewal cron.
 *
 * @return array the result of wallos_advance_schedule()
 */
function wallos_apply_schedule_advance(SQLite3 $db, array $subscription, DateTimeInterface $today)
{
    $advance = wallos_advance_schedule($subscription, $today);
    if ($advance['due_dates'] === [] && !$advance['ended']) {
        return $advance;
    }

    wallos_ledger_record_cycles($db, $subscription, $advance['due_dates']);

    $fields = [
        'next_payment' => $advance['next_payment'],
        'payments_made' => (int) ($subscription['payments_made'] ?? 0) + count($advance['due_dates']),
    ];
    if ($advance['ended']) {
        $fields['inactive'] = 1;
        $fields['ended_reason'] = 'completed';
    }
    wallos_update_schedule($db, $subscription, $fields);

    return $advance;
}

/**
 * Marks the current cycle of a recurring subscription as paid.
 *
 * Manual renewals: records the payment for next_payment and moves
 * next_payment one cycle on (or one cycle from today when the subscription
 * renews from the day it is paid). An overdue subscription several cycles
 * behind is confirmed one cycle per call, so every cycle gets its own row.
 *
 * Automatic renewals: confirms the most recent cycle the cron moved past
 * without confirmation; when there is none, records the upcoming cycle as
 * paid early and leaves next_payment alone, so the cron does not skip it twice.
 *
 * @param float|null $amount null keeps the list price (or the amount already recorded)
 * @return array{success: bool, error?: string, due_date?: string, next_payment?: string, ended?: bool}
 */
function wallos_mark_paid(SQLite3 $db, array $subscription, DateTimeInterface $today, $amount = null)
{
    if (!wallos_is_recurring($subscription)) {
        return ['success' => false, 'error' => 'not_recurring'];
    }
    if ((int) $subscription['inactive'] === 1) {
        return ['success' => false, 'error' => 'inactive'];
    }
    if ($amount !== null && (!is_numeric($amount) || (float) $amount < 0)) {
        return ['success' => false, 'error' => 'invalid_amount'];
    }
    $todayString = $today->format('Y-m-d');
    $next = wallos_parse_date($subscription['next_payment']);
    if ($next === null) {
        return ['success' => false, 'error' => 'no_next_payment'];
    }

    if ((int) $subscription['auto_renew'] === 1) {
        $stmt = $db->prepare("SELECT * FROM subscription_payments WHERE subscription_id = :id
            AND status IN ('assumed', 'pending_amount') AND due_date < :next ORDER BY due_date DESC LIMIT 1");
        $stmt->bindValue(':id', (int) $subscription['id'], SQLITE3_INTEGER);
        $stmt->bindValue(':next', $next->format('Y-m-d'), SQLITE3_TEXT);
        $open = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

        if ($open !== false) {
            $value = $amount ?? ($open['amount'] ?? $subscription['price']);
            wallos_ledger_upsert_paid($db, $subscription, $open['due_date'], $todayString, $value, 'manual');

            return ['success' => true, 'due_date' => $open['due_date'], 'next_payment' => $next->format('Y-m-d'), 'ended' => false];
        }

        wallos_ledger_upsert_paid($db, $subscription, $next->format('Y-m-d'), $todayString, $amount ?? $subscription['price'], 'manual');

        return ['success' => true, 'due_date' => $next->format('Y-m-d'), 'next_payment' => $next->format('Y-m-d'), 'ended' => false];
    }

    $dueDate = $next->format('Y-m-d');
    $fields = ['payments_made' => (int) ($subscription['payments_made'] ?? 0) + 1];
    if ((int) ($subscription['renew_from_action'] ?? 0) === 1) {
        // Restart the cycle on the day of payment, anchored to that day.
        $anchor = in_array((int) $subscription['cycle'], [3, 4], true) ? (int) $today->format('j') : null;
        $reanchored = array_merge($subscription, ['billing_anchor_day' => $anchor]);
        $newNext = wallos_next_occurrence($reanchored, $today);
        $fields['billing_anchor_day'] = $anchor;
    } else {
        $newNext = wallos_next_occurrence($subscription, $next);
    }

    $ended = false;
    $planEnd = wallos_subscription_end_date($subscription);
    if ($planEnd !== null && $newNext > $planEnd) {
        // That was the final payment of the plan.
        $ended = true;
        $fields['inactive'] = 1;
        $fields['ended_reason'] = 'completed';
        $fields['next_payment'] = $dueDate;
    } else {
        $fields['next_payment'] = $newNext->format('Y-m-d');
    }

    wallos_ledger_upsert_paid($db, $subscription, $dueDate, $todayString, $amount ?? $subscription['price'], 'manual');
    wallos_update_schedule($db, $subscription, $fields);

    return ['success' => true, 'due_date' => $dueDate, 'next_payment' => $fields['next_payment'], 'ended' => $ended];
}

/**
 * Takes back a "Mark paid".
 *
 * Manual renewals: removes the row and moves next_payment back to its due
 * date (only for the most recent payment, so the schedule stays consistent).
 * Automatic renewals: a cycle the cron already moved past goes back to
 * unconfirmed; an early payment for the upcoming cycle is removed.
 *
 * @return array{success: bool, error?: string}
 */
function wallos_undo_paid(SQLite3 $db, array $subscription, $dueDate)
{
    $stmt = $db->prepare("SELECT * FROM subscription_payments WHERE subscription_id = :id AND due_date = :dueDate AND status = 'paid'");
    $stmt->bindValue(':id', (int) $subscription['id'], SQLITE3_INTEGER);
    $stmt->bindValue(':dueDate', (string) $dueDate, SQLITE3_TEXT);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if ($row === false) {
        return ['success' => false, 'error' => 'not_found'];
    }

    $delete = $db->prepare('DELETE FROM subscription_payments WHERE id = :id');
    $delete->bindValue(':id', (int) $row['id'], SQLITE3_INTEGER);

    if ((int) $subscription['auto_renew'] !== 1) {
        $latest = $db->prepare('SELECT MAX(due_date) FROM subscription_payments WHERE subscription_id = :id');
        $latest->bindValue(':id', (int) $subscription['id'], SQLITE3_INTEGER);
        $latestDue = $latest->execute()->fetchArray(SQLITE3_NUM)[0] ?? null;
        if ($latestDue !== $row['due_date'] || $row['due_date'] > $subscription['next_payment']) {
            return ['success' => false, 'error' => 'not_latest'];
        }

        $delete->execute();
        $fields = [
            'next_payment' => $row['due_date'],
            'payments_made' => max(0, (int) ($subscription['payments_made'] ?? 0) - 1),
            'billing_anchor_day' => wallos_anchor_day_for_save($row['due_date'], $subscription['start_date'] ?? null,
                $subscription['cycle'], $subscription['billing_anchor_day'] ?? null),
        ];
        if (($subscription['ended_reason'] ?? null) === 'completed') {
            $fields['inactive'] = 0;
            $fields['ended_reason'] = null;
        }
        wallos_update_schedule($db, $subscription, $fields);

        return ['success' => true];
    }

    if ($row['due_date'] >= $subscription['next_payment']) {
        $delete->execute();

        return ['success' => true];
    }

    $variable = (int) ($subscription['variable_amount'] ?? 0) === 1;
    $revert = $db->prepare("UPDATE subscription_payments SET status = :status, source = 'auto',
        paid_date = :paidDate, amount = :amount WHERE id = :id");
    $revert->bindValue(':status', $variable ? 'pending_amount' : 'assumed', SQLITE3_TEXT);
    $revert->bindValue(':paidDate', $variable ? null : $row['due_date'], $variable ? SQLITE3_NULL : SQLITE3_TEXT);
    $revert->bindValue(':amount', $variable ? null : (float) $subscription['price'], $variable ? SQLITE3_NULL : SQLITE3_FLOAT);
    $revert->bindValue(':id', (int) $row['id'], SQLITE3_INTEGER);
    $revert->execute();

    return ['success' => true];
}

/**
 * The most recent ledger rows of one subscription, newest first.
 *
 * @return array[]
 */
function wallos_ledger_list(SQLite3 $db, $userId, $subscriptionId, $limit = 12)
{
    $stmt = $db->prepare('SELECT id, due_date, paid_date, amount, currency_id, status, source, note
        FROM subscription_payments WHERE subscription_id = :id AND user_id = :userId
        ORDER BY due_date DESC LIMIT :limit');
    $stmt->bindValue(':id', (int) $subscriptionId, SQLITE3_INTEGER);
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':limit', max(1, (int) $limit), SQLITE3_INTEGER);
    $result = $stmt->execute();
    $rows = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $row['id'] = (int) $row['id'];
        $row['amount'] = $row['amount'] === null ? null : (float) $row['amount'];
        $rows[] = $row;
    }

    return $rows;
}

/**
 * Edits one ledger row: the amount, the paid date and the note. Entering an
 * amount confirms the row.
 *
 * @return bool
 */
function wallos_ledger_save(SQLite3 $db, $userId, $paymentId, $amount, $paidDate, $note)
{
    if (!is_numeric($amount) || (float) $amount < 0) {
        return false;
    }
    $paid = wallos_parse_date($paidDate);
    $stmt = $db->prepare("UPDATE subscription_payments SET amount = :amount, paid_date = :paidDate,
        note = :note, status = 'paid', source = 'manual' WHERE id = :id AND user_id = :userId");
    $stmt->bindValue(':amount', (float) $amount, SQLITE3_FLOAT);
    $stmt->bindValue(':paidDate', $paid ? $paid->format('Y-m-d') : null, $paid ? SQLITE3_TEXT : SQLITE3_NULL);
    $note = trim((string) $note);
    $stmt->bindValue(':note', $note === '' ? null : mb_substr($note, 0, 500), $note === '' ? SQLITE3_NULL : SQLITE3_TEXT);
    $stmt->bindValue(':id', (int) $paymentId, SQLITE3_INTEGER);
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
    $stmt->execute();

    return $db->changes() === 1;
}

/**
 * @return bool
 */
function wallos_ledger_delete(SQLite3 $db, $userId, $paymentId)
{
    $stmt = $db->prepare('DELETE FROM subscription_payments WHERE id = :id AND user_id = :userId');
    $stmt->bindValue(':id', (int) $paymentId, SQLITE3_INTEGER);
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
    $stmt->execute();

    return $db->changes() === 1;
}

/**
 * The latest confirmed payment of each of a user's subscriptions, in one query.
 *
 * @return array<int, array{due_date: string, paid_date: ?string}>
 */
function wallos_ledger_last_paid_map(SQLite3 $db, $userId)
{
    $stmt = $db->prepare("SELECT p.subscription_id, p.due_date, p.paid_date FROM subscription_payments p
        JOIN (SELECT subscription_id, MAX(due_date) AS due_date FROM subscription_payments
              WHERE user_id = :userId AND status = 'paid' GROUP BY subscription_id) latest
        ON latest.subscription_id = p.subscription_id AND latest.due_date = p.due_date
        WHERE p.user_id = :userId");
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $map = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $map[(int) $row['subscription_id']] = ['due_date' => $row['due_date'], 'paid_date' => $row['paid_date']];
    }

    return $map;
}

/**
 * The paid badge for a list row: the cycle just before next_payment (or the
 * upcoming one, paid early) is confirmed. Shown until the next due date.
 *
 * @param array|null $lastPaid an entry of wallos_ledger_last_paid_map()
 * @return string|null the paid date to show, or null for no badge
 */
function wallos_paid_badge_date(array $subscription, $lastPaid)
{
    if ($lastPaid === null || !wallos_is_recurring($subscription) || (int) $subscription['inactive'] === 1) {
        return null;
    }
    $next = wallos_parse_date($subscription['next_payment'] ?? null);
    if ($next === null) {
        return null;
    }
    $previous = wallos_previous_occurrence($subscription, $next);
    if ($lastPaid['due_date'] < $previous->format('Y-m-d')) {
        return null;
    }

    return $lastPaid['paid_date'] ?: $lastPaid['due_date'];
}

/**
 * Cycles that need the user's attention on the dashboard: amounts still to be
 * entered, and (with the require-confirmation setting) cycles the cron
 * advanced on its own.
 *
 * @return array[] newest first, joined with the subscription's name, logo and price
 */
function wallos_ledger_open_items(SQLite3 $db, $userId, $includeAssumed, $limit = 20)
{
    $statuses = $includeAssumed ? "('pending_amount', 'assumed')" : "('pending_amount')";
    $stmt = $db->prepare("SELECT p.id AS payment_id, p.due_date, p.status, p.amount AS ledger_amount,
            s.id, s.name, s.logo, s.logo_variant, s.logo_text_color, s.price, s.currency_id
        FROM subscription_payments p JOIN subscriptions s ON s.id = p.subscription_id
        WHERE p.user_id = :userId AND p.status IN $statuses
        ORDER BY p.due_date DESC LIMIT :limit");
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':limit', max(1, (int) $limit), SQLITE3_INTEGER);
    $result = $stmt->execute();
    $rows = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }

    return $rows;
}

/**
 * Actual spend per month from the ledger, in the main currency.
 *
 * @param string $fromMonth 'Y-m', inclusive
 * @param string $toMonth   'Y-m', inclusive
 * @return array<string, float> 'Y-m' => total, every month in the range present
 */
function wallos_ledger_monthly_totals(SQLite3 $db, $userId, $fromMonth, $toMonth)
{
    $totals = [];
    $cursor = DateTime::createFromFormat('!Y-m', $fromMonth);
    $last = DateTime::createFromFormat('!Y-m', $toMonth);
    while ($cursor && $last && $cursor <= $last && count($totals) < 240) {
        $totals[$cursor->format('Y-m')] = 0.0;
        $cursor->modify('+1 month');
    }

    $stmt = $db->prepare("SELECT substr(due_date, 1, 7) AS month, currency_id, SUM(amount) AS total
        FROM subscription_payments
        WHERE user_id = :userId AND amount IS NOT NULL AND due_date >= :from AND due_date < :to
        GROUP BY month, currency_id");
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':from', $fromMonth . '-01', SQLITE3_TEXT);
    $stmt->bindValue(':to', (DateTime::createFromFormat('!Y-m', $toMonth) ?: new DateTime())->modify('+1 month')->format('Y-m-01'), SQLITE3_TEXT);
    $result = $stmt->execute();
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        if (isset($totals[$row['month']])) {
            $totals[$row['month']] += wallos_convert_price((float) $row['total'], $row['currency_id'], $db, $userId);
        }
    }

    return $totals;
}
