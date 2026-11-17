<?php
/*
  Cancellation notice (see includes/cancellation_deadline.php).

  subscriptions
    cancel_notice_mode        'none', 'fixed' (remind on cancellation_date) or
                              'before_renewal' (N days/weeks/months before each
                              renewal).
    cancel_notice_value       N.
    cancel_notice_unit        'days', 'weeks' or 'months'.
    min_term_end              End of a minimum term; the reminder is for the
                              first renewal on or after it.
    last_cancel_notified_for  The cancel-by date the last reminder was sent
                              for, so each one is sent once and a missed cron
                              day no longer loses it.

  settings
    default_cancel_notice_value / _unit  Prefilled when a subscription is set
                              to remind before each renewal.

  Rows with a cancellation date become 'fixed'. Dates already in the past
  are marked as reminded, so the upgrade does not send a burst of old ones.

  Safe to run twice.
*/

$subscriptionColumns = [
    'cancel_notice_mode' => 'TEXT DEFAULT NULL',
    'cancel_notice_value' => 'INTEGER DEFAULT NULL',
    'cancel_notice_unit' => 'TEXT DEFAULT NULL',
    'min_term_end' => 'DATE DEFAULT NULL',
    'last_cancel_notified_for' => 'TEXT DEFAULT NULL',
];

$addedMode = false;
foreach ($subscriptionColumns as $column => $definition) {
    $columnQuery = $db->query("SELECT * FROM pragma_table_info('subscriptions') WHERE name = '$column'");
    if ($columnQuery->fetchArray(SQLITE3_ASSOC) === false) {
        $db->exec("ALTER TABLE subscriptions ADD COLUMN $column $definition");
        if ($column === 'cancel_notice_mode') {
            $addedMode = true;
        }
    }
}

foreach (['default_cancel_notice_value' => 'INTEGER DEFAULT NULL', 'default_cancel_notice_unit' => "TEXT DEFAULT 'months'"] as $column => $definition) {
    $columnQuery = $db->query("SELECT * FROM pragma_table_info('settings') WHERE name = '$column'");
    if ($columnQuery->fetchArray(SQLITE3_ASSOC) === false) {
        $db->exec("ALTER TABLE settings ADD COLUMN $column $definition");
    }
}

if ($addedMode) {
    $db->exec("UPDATE subscriptions SET cancel_notice_mode = 'fixed'
        WHERE cancellation_date IS NOT NULL AND cancellation_date != ''");
    $db->exec("UPDATE subscriptions SET cancel_notice_mode = 'none'
        WHERE cancel_notice_mode IS NULL");

    // The old cron only notified on the exact day; anything before today
    // either got its reminder or missed it long ago.
    $stmt = $db->prepare("UPDATE subscriptions SET last_cancel_notified_for = cancellation_date
        WHERE cancel_notice_mode = 'fixed' AND cancellation_date < :today");
    $stmt->bindValue(':today', (new DateTime('now'))->format('Y-m-d'), SQLITE3_TEXT);
    $stmt->execute();
}
