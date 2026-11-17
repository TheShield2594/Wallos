<?php
/*
  settings.show_category_card: show the "By category" card on the dashboard.
  On by default; Settings > Display hides it.

  Safe to run twice.
*/

$columnQuery = $db->query("SELECT * FROM pragma_table_info('settings') WHERE name = 'show_category_card'");
if ($columnQuery->fetchArray(SQLITE3_ASSOC) === false) {
    $db->exec("ALTER TABLE settings ADD COLUMN show_category_card BOOLEAN DEFAULT 1");
}
