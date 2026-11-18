<?php
/*
  Shared households follow-ups (issue #59).

  household.linked_user_id   a payer label ("Paid by") can point to a real
                             Tally account in the owner's shared household.
                             Cleared when either account leaves it.

  notification_recipient_log
                             reminders a shared subscription's linked payer
                             gets as well as its owner, logged per recipient
                             (user_id) so each goes out once. kind 'payment'
                             logs offsets like notification_log; 'cancel' and
                             'intro' log the marker the owner's row keeps in
                             last_cancel_notified_for / intro_notified_for,
                             in due_date, with offset 0.

  Safe to run twice.
*/

$migrate = function ($sql) use ($db) {
    if ($db->exec($sql) === false) {
        throw new RuntimeException('Migration 000077 failed: ' . $db->lastErrorMsg());
    }
};

$hasColumn = function ($table, $column) use ($db) {
    $result = $db->query("SELECT 1 FROM pragma_table_info('" . $table . "') WHERE name = '" . $column . "'");
    if ($result === false) {
        throw new RuntimeException('Migration 000077 failed: ' . $db->lastErrorMsg());
    }

    return $result->fetchArray(SQLITE3_NUM) !== false;
};

if (!$hasColumn('household', 'linked_user_id')) {
    $migrate("ALTER TABLE household ADD COLUMN linked_user_id INTEGER DEFAULT NULL");
}

$migrate("CREATE TABLE IF NOT EXISTS notification_recipient_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    owner_user_id INTEGER NOT NULL,
    subscription_id INTEGER NOT NULL,
    kind TEXT NOT NULL,
    due_date TEXT NOT NULL,
    \"offset\" INTEGER NOT NULL DEFAULT 0,
    sent_at TEXT NOT NULL
)");
$migrate("CREATE UNIQUE INDEX IF NOT EXISTS idx_notification_recipient_log_unique
    ON notification_recipient_log (user_id, subscription_id, kind, due_date, \"offset\")");
$migrate("CREATE INDEX IF NOT EXISTS idx_notification_recipient_log_owner ON notification_recipient_log (owner_user_id, subscription_id)");
