<?php
/*
  Notifications rework (issues #37, #38, #39, #40).

  notification_settings
    reminder_days          comma-separated default reminder offsets ("7,3,0");
                           NULL means the single legacy `days` value.
    send_time              "HH:MM" local time the reminders go out (15-minute steps).
    timezone               IANA time zone; NULL/'' means the container's TZ.
    repeat_every           overdue repeat interval in days for non-auto-renew
                           subscriptions still unpaid (0 = off).
    repeat_max             how many overdue repeats at most.
    last_payment_run       the local date payment reminders last ran for this user,
    last_cancellation_run  and cancellation reminders: the cron runs every 15
                           minutes and processes each user once a day.

  subscriptions
    reminder_days                    per-subscription reminder offsets (NULL = use
                                     notify_days_before / the global default).
    cancellation_notify_days_before  lead time before the cancel-by date.
    mute_payment_until_cancellation  no payment reminders for payments up to the
                                     cancel-by date (a fixed-term contract).

  notification_log         one row per reminder sent, so a cron that runs every
                           15 minutes never sends the same reminder twice.
  notification_templates   user-defined message templates.
  apprise_notifications    the Apprise API channel.

  Safe to run twice: a failed statement throws, so the runner does not
  record the migration.
*/

$migrate = function ($sql) use ($db) {
    if ($db->exec($sql) === false) {
        throw new RuntimeException('Migration 000074 failed: ' . $db->lastErrorMsg());
    }
};

$hasColumn = function ($table, $column) use ($db) {
    $result = $db->query("SELECT 1 FROM pragma_table_info('" . $table . "') WHERE name = '" . $column . "'");
    if ($result === false) {
        throw new RuntimeException('Migration 000074 failed: ' . $db->lastErrorMsg());
    }

    return $result->fetchArray(SQLITE3_NUM) !== false;
};

$columns = [
    'notification_settings' => [
        'reminder_days' => "TEXT DEFAULT NULL",
        'send_time' => "TEXT DEFAULT '09:00'",
        'timezone' => "TEXT DEFAULT NULL",
        'repeat_every' => "INTEGER DEFAULT 0",
        'repeat_max' => "INTEGER DEFAULT 0",
        'last_payment_run' => "TEXT DEFAULT NULL",
        'last_cancellation_run' => "TEXT DEFAULT NULL",
    ],
    'subscriptions' => [
        'reminder_days' => "TEXT DEFAULT NULL",
        'cancellation_notify_days_before' => "INTEGER DEFAULT 0",
        'mute_payment_until_cancellation' => "INTEGER DEFAULT 0",
    ],
];

foreach ($columns as $table => $tableColumns) {
    foreach ($tableColumns as $column => $definition) {
        if (!$hasColumn($table, $column)) {
            $migrate("ALTER TABLE " . $table . " ADD COLUMN " . $column . " " . $definition);
        }
    }
}

$migrate("CREATE TABLE IF NOT EXISTS notification_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    subscription_id INTEGER NOT NULL,
    kind TEXT NOT NULL,
    due_date TEXT NOT NULL,
    \"offset\" INTEGER NOT NULL,
    sent_at TEXT NOT NULL
)");
$migrate("CREATE UNIQUE INDEX IF NOT EXISTS idx_notification_log_unique ON notification_log (subscription_id, kind, due_date, \"offset\")");
$migrate("CREATE INDEX IF NOT EXISTS idx_notification_log_user ON notification_log (user_id)");

$migrate("CREATE TABLE IF NOT EXISTS notification_templates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    kind TEXT NOT NULL,
    channel TEXT DEFAULT NULL,
    title TEXT NOT NULL DEFAULT '',
    body TEXT NOT NULL DEFAULT ''
)");
$migrate("CREATE UNIQUE INDEX IF NOT EXISTS idx_notification_templates_unique ON notification_templates (user_id, kind, COALESCE(channel, ''))");

$migrate("CREATE TABLE IF NOT EXISTS apprise_notifications (
    user_id INTEGER PRIMARY KEY,
    enabled INTEGER DEFAULT 0,
    url TEXT DEFAULT '',
    mode TEXT DEFAULT 'key',
    config_key TEXT DEFAULT '',
    urls TEXT DEFAULT '',
    tags TEXT DEFAULT '',
    ignore_ssl INTEGER DEFAULT 0
)");
