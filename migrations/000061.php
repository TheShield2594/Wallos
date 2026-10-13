<?php
// Renewal reminders are now sent on any day inside a subscription's reminder
// window instead of only on its first day, so a missed cron run no longer
// loses the reminder (see includes/renewal_reminders.php). This column
// records which payment date the last reminder was for, so each payment is
// still reminded only once.
//
// Subscriptions already past the first day of their window were reminded
// then (or that run was missed before this fix existed); mark them as done
// so the upgrade does not send a burst of repeat reminders. A subscription
// whose window starts today is left alone and is reminded as usual.

$columnQuery = $db->query("SELECT * FROM pragma_table_info('subscriptions') where name='last_notified_payment'");
$columnRequired = $columnQuery->fetchArray(SQLITE3_ASSOC) === false;

if ($columnRequired) {
    $db->exec("ALTER TABLE subscriptions ADD COLUMN last_notified_payment TEXT DEFAULT NULL");

    $stmt = $db->prepare("UPDATE subscriptions SET last_notified_payment = next_payment
        WHERE notify = 1 AND inactive = 0 AND next_payment >= :today
        AND julianday(next_payment) - julianday(:today) < COALESCE(
            NULLIF(notify_days_before, -1),
            (SELECT days FROM notification_settings WHERE notification_settings.user_id = subscriptions.user_id),
            1
        )");
    $stmt->bindValue(':today', (new DateTime('now'))->format('Y-m-d'), SQLITE3_TEXT);
    $stmt->execute();
}
