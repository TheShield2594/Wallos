<?php
/*
  Bundled subscriptions (issue #24, see includes/bundles.php).

  subscriptions.parent_subscription_id   the subscription this one is billed
                                         through ("included in"). NULL for a
                                         standalone subscription.
  subscriptions.allocated_amount         optional part of the parent's price
                                         attributed to this child, in the
                                         parent's currency, per parent cycle.

  Safe to run twice: a failed statement throws, so the runner does not
  record the migration.
*/

$migrate = function ($sql) use ($db) {
    if ($db->exec($sql) === false) {
        throw new RuntimeException('Migration 000078 failed: ' . $db->lastErrorMsg());
    }
};

$hasColumn = function ($table, $column) use ($db) {
    $result = $db->query("SELECT 1 FROM pragma_table_info('" . $table . "') WHERE name = '" . $column . "'");
    if ($result === false) {
        throw new RuntimeException('Migration 000078 failed: ' . $db->lastErrorMsg());
    }

    return $result->fetchArray(SQLITE3_NUM) !== false;
};

if (!$hasColumn('subscriptions', 'parent_subscription_id')) {
    $migrate("ALTER TABLE subscriptions ADD COLUMN parent_subscription_id INTEGER NULL REFERENCES subscriptions(id)");
}
if (!$hasColumn('subscriptions', 'allocated_amount')) {
    $migrate("ALTER TABLE subscriptions ADD COLUMN allocated_amount REAL NULL");
}
$migrate("CREATE INDEX IF NOT EXISTS idx_subscriptions_parent ON subscriptions (user_id, parent_subscription_id)");
