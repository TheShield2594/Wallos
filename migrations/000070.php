<?php
/*
  Per-currency decimal places (see includes/currency_formatter.php).

  currencies.decimals  0-8 digits after the decimal point, or NULL for
                       automatic (ICU's digits for ISO codes, up to 8 for
                       codes such as BTC that ICU does not know).

  Safe to run twice: a failed statement throws, so the runner does not
  record the migration.
*/

$columnQuery = $db->query("SELECT * FROM pragma_table_info('currencies') WHERE name = 'decimals'");
if ($columnQuery === false) {
    throw new RuntimeException('Migration 000070 failed: ' . $db->lastErrorMsg());
}
if ($columnQuery->fetchArray(SQLITE3_ASSOC) === false
    && $db->exec("ALTER TABLE currencies ADD COLUMN decimals INTEGER DEFAULT NULL") === false) {
    throw new RuntimeException('Migration 000070 failed: ' . $db->lastErrorMsg());
}
