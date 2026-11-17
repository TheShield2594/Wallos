<?php
/*
  Date format (see includes/date_format.php).

  settings.date_format
    'auto'     the pattern the UI language suggests (the behaviour before)
    'dmy'      31/12/2026
    'mdy'      12/31/2026
    'iso'      2026-12-31
    'd_mon_y'  31 Dec 2026
    'mon_d_y'  Dec 31, 2026

  Safe to run twice: a failed statement throws, so the runner does not
  record the migration.
*/

$columnQuery = $db->query("SELECT * FROM pragma_table_info('settings') WHERE name = 'date_format'");
if ($columnQuery === false) {
    throw new RuntimeException('Migration 000069 failed: ' . $db->lastErrorMsg());
}
if ($columnQuery->fetchArray(SQLITE3_ASSOC) === false
    && $db->exec("ALTER TABLE settings ADD COLUMN date_format TEXT DEFAULT 'auto'") === false) {
    throw new RuntimeException('Migration 000069 failed: ' . $db->lastErrorMsg());
}
