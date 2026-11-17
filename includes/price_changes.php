<?php
/*
  Price and plan changes (migration 000068).

  Editing a subscription overwrites its price, so before this the old value
  was lost. Now every edit that changes the price, the currency, the cycle or
  the frequency appends a row to subscription_price_changes, labelled with
  the optional plan/tier name ("Ultimate → Core") and a reason:

    tier      the user changed plan (a downgrade saves, an upgrade costs)
    provider  the provider changed the price (inflation)

  Both amounts are also stored spread per month in the new currency, so a
  monthly plan swapped for a yearly one, or a change of currency, still
  compares directly.

  The details panel shows the timeline; the statistics page adds a
  "Plan changes" card, and downgrade savings count towards "Your savings".
*/

require_once __DIR__ . '/currency_rates.php';
require_once __DIR__ . '/monthly_cost.php';

const WALLOS_PRICE_CHANGE_REASONS = ['tier', 'provider'];
const WALLOS_TIER_NAME_MAX_LENGTH = 60;

/**
 * A tier name as stored: trimmed, at most 60 characters, null when empty.
 *
 * @param mixed $value
 * @return string|null
 */
function wallos_normalize_tier_name($value)
{
    if (!is_string($value)) {
        return null;
    }
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    if ($value === '') {
        return null;
    }

    return mb_substr($value, 0, WALLOS_TIER_NAME_MAX_LENGTH);
}

/**
 * Converts an amount between two of the user's currencies at the stored
 * rates (each rate is per unit of the main currency).
 *
 * Unlike the display conversion, a missing or zero rate gives null rather
 * than the unconverted amount: a history row is stored once and never
 * recalculated, so it must not mix two currencies.
 *
 * @return float|null
 */
function wallos_convert_between_currencies($amount, $fromCurrencyId, $toCurrencyId, SQLite3 $db, $userId)
{
    if ((int) $fromCurrencyId === (int) $toCurrencyId) {
        return (float) $amount;
    }
    $rates = wallos_currency_rates($db, $userId);
    $from = $rates[(int) $fromCurrencyId] ?? null;
    $to = $rates[(int) $toCurrencyId] ?? null;
    if (empty($from) || empty($to)) {
        return null;
    }

    return (float) $amount / $from * $to;
}

/**
 * The fields of a subscription that a price change compares, read from the
 * database. Callers read it inside their write transaction, so two edits in
 * a row each record the price the other one left.
 *
 * @return array|null price, currency_id, cycle, frequency, tier_name
 */
function wallos_price_snapshot(SQLite3 $db, $userId, $subscriptionId)
{
    $stmt = $db->prepare('SELECT price, currency_id, cycle, frequency, tier_name FROM subscriptions WHERE id = :id AND user_id = :userId');
    $stmt->bindValue(':id', (int) $subscriptionId, SQLITE3_INTEGER);
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;

    return $row === false ? null : $row;
}

/**
 * Whether an edit changed what the subscription costs.
 *
 * @param array $old the stored row: price, currency_id, cycle, frequency
 * @param array $new the same fields as saved
 * @return bool
 */
function wallos_price_changed(array $old, array $new)
{
    if (!isset($old['price'])) {
        return false;
    }

    return abs((float) $old['price'] - (float) $new['price']) >= 0.005
        || (int) $old['currency_id'] !== (int) $new['currency_id']
        || (int) $old['cycle'] !== (int) $new['cycle']
        || (int) $old['frequency'] !== (int) $new['frequency'];
}

/**
 * The row to store for a change, or null when nothing that affects the cost
 * changed. One-time purchases on either side have no monthly amount.
 *
 * @param array    $old       stored row: price, currency_id, cycle, frequency, tier_name
 * @param array    $new       saved row: the same fields
 * @param string   $reason    'tier' or 'provider'
 * @param string   $changedAt Y-m-d
 * @param callable $convert   fn(float $amount, int $fromCurrencyId, int $toCurrencyId): ?float,
 *                            null when there is no usable rate (the row then has no monthly amounts)
 * @return array|null
 */
function wallos_price_change_row(array $old, array $new, $reason, $changedAt, callable $convert)
{
    if (!wallos_price_changed($old, $new)) {
        return null;
    }
    $reason = in_array($reason, WALLOS_PRICE_CHANGE_REASONS, true) ? $reason : 'tier';
    $isOneTime = (int) $old['cycle'] === 5 || (int) $new['cycle'] === 5;
    $oldMonthly = $isOneTime ? null : $convert(
        wallos_price_per_month($old['cycle'], $old['frequency'], (float) $old['price']),
        (int) $old['currency_id'],
        (int) $new['currency_id']
    );
    // Without a rate for both currencies the amounts cannot be compared: keep
    // the prices, store no monthly amounts (the totals skip such rows).
    $newMonthly = ($isOneTime || $oldMonthly === null) ? null : wallos_price_per_month($new['cycle'], $new['frequency'], (float) $new['price']);

    return [
        'changed_at' => $changedAt,
        'old_price' => (float) $old['price'],
        'new_price' => (float) $new['price'],
        'old_currency_id' => (int) $old['currency_id'],
        'currency_id' => (int) $new['currency_id'],
        'old_cycle' => (int) $old['cycle'],
        'new_cycle' => (int) $new['cycle'],
        'old_frequency' => (int) $old['frequency'],
        'new_frequency' => (int) $new['frequency'],
        'old_monthly' => $oldMonthly === null ? null : round($oldMonthly, 6),
        'new_monthly' => $newMonthly === null ? null : round($newMonthly, 6),
        'old_tier' => wallos_normalize_tier_name($old['tier_name'] ?? null),
        'new_tier' => wallos_normalize_tier_name($new['tier_name'] ?? null),
        'reason' => $reason,
    ];
}

/**
 * Appends a change for a subscription when the edit changed its cost.
 *
 * @return bool false only when the insert failed
 */
function wallos_record_price_change(SQLite3 $db, $userId, $subscriptionId, array $old, array $new, $reason, $changedAt = null)
{
    $row = wallos_price_change_row($old, $new, $reason, $changedAt ?? date('Y-m-d'),
        function ($amount, $from, $to) use ($db, $userId) {
            return wallos_convert_between_currencies($amount, $from, $to, $db, $userId);
        });
    if ($row === null) {
        return true;
    }

    $columns = array_keys($row);
    $stmt = $db->prepare('INSERT INTO subscription_price_changes (subscription_id, user_id, ' . implode(', ', $columns) . ')
        VALUES (:subscription_id, :user_id, :' . implode(', :', $columns) . ')');
    if ($stmt === false) {
        return false;
    }
    $stmt->bindValue(':subscription_id', (int) $subscriptionId, SQLITE3_INTEGER);
    $stmt->bindValue(':user_id', (int) $userId, SQLITE3_INTEGER);
    foreach ($row as $column => $value) {
        $type = $value === null ? SQLITE3_NULL : (is_float($value) ? SQLITE3_FLOAT : (is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT));
        $stmt->bindValue(':' . $column, $value, $type);
    }

    return $stmt->execute() !== false;
}

/**
 * The changes of one subscription, newest first.
 *
 * @return array[]
 */
function wallos_price_changes_list(SQLite3 $db, $userId, $subscriptionId, $limit = 50)
{
    $stmt = $db->prepare('SELECT * FROM subscription_price_changes WHERE subscription_id = :id AND user_id = :userId
        ORDER BY changed_at DESC, id DESC LIMIT :limit');
    $stmt->bindValue(':id', (int) $subscriptionId, SQLITE3_INTEGER);
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':limit', max(1, (int) $limit), SQLITE3_INTEGER);
    $result = $stmt->execute();

    $rows = [];
    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $rows[] = $row;
    }

    return $rows;
}

/**
 * Totals for the "Plan changes" card, in the main currency.
 *
 * Each change contributes its monthly delta (new - old) from the day it was
 * made: as a run-rate (per month now) and accrued (summed over the months
 * since). Plan downgrades are savings, plan upgrades extra spend, and the
 * provider's changes are price increases (or the odd decrease).
 *
 * @param array    $changes rows with changed_at, old_monthly, new_monthly, currency_id, reason
 * @param string   $today   Y-m-d
 * @param callable $toMain  fn(float $amount, int $currencyId): float
 * @return array{downgrade_monthly: float, downgrade_accrued: float, upgrade_monthly: float, upgrade_accrued: float,
 *               provider_monthly: float, provider_accrued: float, count: int}
 */
function wallos_price_change_totals(array $changes, $today, callable $toMain)
{
    $totals = [
        'downgrade_monthly' => 0.0,
        'downgrade_accrued' => 0.0,
        'upgrade_monthly' => 0.0,
        'upgrade_accrued' => 0.0,
        'provider_monthly' => 0.0,
        'provider_accrued' => 0.0,
        'count' => 0,
    ];
    $todayDate = new DateTime($today);

    foreach ($changes as $change) {
        if ($change['old_monthly'] === null || $change['new_monthly'] === null) {
            continue;
        }
        $delta = (float) $toMain((float) $change['new_monthly'] - (float) $change['old_monthly'], (int) $change['currency_id']);
        if (abs($delta) < 0.005) {
            continue;
        }
        $changedAt = new DateTime(substr((string) $change['changed_at'], 0, 10));
        $days = $changedAt > $todayDate ? 0 : (int) $changedAt->diff($todayDate)->format('%a');
        $months = $days / 30.4375;
        $totals['count']++;

        if (($change['reason'] ?? 'tier') === 'provider') {
            $totals['provider_monthly'] += $delta;
            $totals['provider_accrued'] += $delta * $months;
        } elseif ($delta < 0) {
            $totals['downgrade_monthly'] += -$delta;
            $totals['downgrade_accrued'] += -$delta * $months;
        } else {
            $totals['upgrade_monthly'] += $delta;
            $totals['upgrade_accrued'] += $delta * $months;
        }
    }

    return $totals;
}

/**
 * Every change of the account's active subscriptions, for the totals.
 *
 * @return array[]
 */
function wallos_active_price_changes(SQLite3 $db, $userId)
{
    $stmt = $db->prepare('SELECT c.* FROM subscription_price_changes c
        INNER JOIN subscriptions s ON s.id = c.subscription_id AND s.user_id = c.user_id
        WHERE c.user_id = :userId AND s.inactive = 0
        ORDER BY c.changed_at ASC, c.id ASC');
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    $rows = [];
    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $rows[] = $row;
    }

    return $rows;
}
