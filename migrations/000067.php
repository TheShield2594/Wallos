<?php
/*
  What the "Monthly cost" figure counts (see includes/monthly_cost.php).

  settings.monthly_cost_mode
    'amortized'     every cycle spread over the months (a yearly 120 counts
                    as 10 a month). The default, and the behaviour before.
    'short_cycles'  only cycles of a month or shorter; longer plans are
                    shown beside it as a yearly amount.
    'cashflow'      what is charged in the current calendar month.

  The yearly cost snapshots (total_yearly_cost) stay amortized whatever the
  setting, so the history chart does not jump.

  Safe to run twice: a failed statement throws, so the runner does not
  record the migration.
*/

$columnQuery = $db->query("SELECT * FROM pragma_table_info('settings') WHERE name = 'monthly_cost_mode'");
if ($columnQuery === false) {
    throw new RuntimeException('Migration 000067 failed: ' . $db->lastErrorMsg());
}
if ($columnQuery->fetchArray(SQLITE3_ASSOC) === false
    && $db->exec("ALTER TABLE settings ADD COLUMN monthly_cost_mode TEXT DEFAULT 'amortized'") === false) {
    throw new RuntimeException('Migration 000067 failed: ' . $db->lastErrorMsg());
}
