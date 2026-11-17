<?php
/*
  Active months (see includes/recurrence.php, issue #25).

  subscriptions.active_months  12-bit mask of the months a daily, weekly or
                               monthly plan bills in: bit 0 = January,
                               4095 = every month (the default and the
                               behaviour before). Payments that fall in an
                               inactive month are skipped.

  Safe to run twice: a failed statement throws, so the runner does not
  record the migration.
*/

$columnQuery = $db->query("SELECT * FROM pragma_table_info('subscriptions') WHERE name = 'active_months'");
if ($columnQuery === false) {
    throw new RuntimeException('Migration 000071 failed: ' . $db->lastErrorMsg());
}
if ($columnQuery->fetchArray(SQLITE3_ASSOC) === false
    && $db->exec("ALTER TABLE subscriptions ADD COLUMN active_months INTEGER NOT NULL DEFAULT 4095") === false) {
    throw new RuntimeException('Migration 000071 failed: ' . $db->lastErrorMsg());
}
