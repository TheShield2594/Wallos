<?php

require_once __DIR__ . '/cancellation_deadline.php';

/**
 * Fetch the subscriptions whose cancel-by date is still ahead of us, soonest
 * first. The date comes from includes/cancellation_deadline.php: a fixed
 * cancellation date, or N days/months before the next renewal, so it moves
 * on by itself when the renewal cron advances next_payment.
 *
 * One-time purchases are excluded: they have no recurring commitment to cancel.
 * The cancellation notification cron applies the same rules.
 *
 * Each row carries 'cancellation_date' (the cancel-by date), 'renewal' (the
 * renewal it avoids, null for a fixed date) and 'locked_until' (the end of a
 * minimum term the next renewal still falls inside).
 *
 * @param SQLite3 $db
 * @param int     $userId
 * @param string|null $today Y-m-d, for tests
 * @return array
 */
function get_upcoming_cancellations($db, $userId, $today = null)
{
    $today = $today ?? (new DateTime('today'))->format('Y-m-d');
    $stmt = $db->prepare("SELECT id, user_id, logo, logo_text_color, logo_variant, name, price, currency_id, cycle, frequency,
            next_payment, start_date, billing_anchor_day, end_date, inactive, cancellation_date,
            cancel_notice_mode, cancel_notice_value, cancel_notice_unit, min_term_end
        FROM subscriptions
        WHERE user_id = :userId
          AND inactive = 0
          AND cycle != 5
          AND (cancel_notice_mode = 'before_renewal'
               OR (cancellation_date IS NOT NULL AND cancellation_date != '' AND COALESCE(cancel_notice_mode, 'fixed') = 'fixed'))");
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    $subscriptions = [];
    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $deadline = wallos_cancel_deadline($row);
        if ($deadline === null || $deadline['cancel_by'] < $today) {
            continue;
        }
        $row['cancellation_date'] = $deadline['cancel_by'];
        $row['renewal'] = $deadline['renewal'];
        $row['locked_until'] = wallos_cancel_locked_until($row);
        $subscriptions[] = $row;
    }

    usort($subscriptions, function ($a, $b) {
        return strcmp($a['cancellation_date'], $b['cancellation_date']) ?: strnatcasecmp($a['name'], $b['name']);
    });

    return $subscriptions;
}

/**
 * Total monthly cost, in the user's main currency, of everything currently
 * flagged for cancellation — what the user would stop spending by cancelling.
 *
 * Expects getPricePerMonth() and getPriceConverted() from stats_calculations.php.
 *
 * @param array   $cancellations rows from get_upcoming_cancellations()
 * @param SQLite3 $db
 * @param int     $userId
 * @return float
 */
function get_upcoming_cancellations_monthly_value($cancellations, $db, $userId)
{
    $total = 0;

    foreach ($cancellations as $subscription) {
        $perMonth = getPricePerMonth($subscription['cycle'], $subscription['frequency'], $subscription['price'], wallos_cycle_active_months($subscription));
        $total += getPriceConverted($perMonth, $subscription['currency_id'], $db, $userId);
    }

    return $total;
}
