<?php
/*
  Free trials and introductory prices (see includes/intro_pricing.php).

  subscriptions
    intro_type, intro_price, intro_end_date, intro_notify_days,
    intro_notified_for, intro_converted_on

  Safe to run twice.
*/

$subscriptionColumns = [
    'intro_type' => 'TEXT DEFAULT NULL',
    'intro_price' => 'REAL DEFAULT NULL',
    'intro_end_date' => 'DATE DEFAULT NULL',
    'intro_notify_days' => 'INTEGER DEFAULT 3',
    'intro_notified_for' => 'TEXT DEFAULT NULL',
    'intro_converted_on' => 'DATE DEFAULT NULL',
];

foreach ($subscriptionColumns as $column => $definition) {
    $columnQuery = $db->query("SELECT * FROM pragma_table_info('subscriptions') WHERE name = '$column'");
    if ($columnQuery->fetchArray(SQLITE3_ASSOC) === false) {
        $db->exec("ALTER TABLE subscriptions ADD COLUMN $column $definition");
    }
}
