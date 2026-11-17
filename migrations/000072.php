<?php
/*
  Cost sharing and reimbursements (see includes/cost_sharing.php, issue #34).

  subscription_shares             how a subscription is split between the
                                  members of Settings > Household, in percent
                                  (adding up to 100). No rows: the payer owns
                                  all of it.
  subscriptions.reimbursed_percent  part of the cost that comes back (tax
                                  relief, an employer); reimbursed_reason is
                                  a short note.
  settings.stats_net_cost         1: the statistics page opens on "My cost"
                                  instead of the gross amounts.

  Safe to run twice: a failed statement throws, so the runner does not
  record the migration.
*/

$migrate = function ($sql) use ($db) {
    if ($db->exec($sql) === false) {
        throw new RuntimeException('Migration 000072 failed: ' . $db->lastErrorMsg());
    }
};

$hasColumn = function ($table, $column) use ($db) {
    $result = $db->query("SELECT 1 FROM pragma_table_info('" . $table . "') WHERE name = '" . $column . "'");
    if ($result === false) {
        throw new RuntimeException('Migration 000072 failed: ' . $db->lastErrorMsg());
    }

    return $result->fetchArray(SQLITE3_NUM) !== false;
};

$migrate("CREATE TABLE IF NOT EXISTS subscription_shares (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    subscription_id INTEGER NOT NULL,
    household_id INTEGER NOT NULL,
    share_percent REAL NOT NULL,
    user_id INTEGER NOT NULL
)");
$migrate("CREATE UNIQUE INDEX IF NOT EXISTS idx_subscription_shares_member ON subscription_shares (subscription_id, household_id)");
$migrate("CREATE INDEX IF NOT EXISTS idx_subscription_shares_user ON subscription_shares (user_id)");

if (!$hasColumn('subscriptions', 'reimbursed_percent')) {
    $migrate("ALTER TABLE subscriptions ADD COLUMN reimbursed_percent REAL NOT NULL DEFAULT 0");
}
if (!$hasColumn('subscriptions', 'reimbursed_reason')) {
    $migrate("ALTER TABLE subscriptions ADD COLUMN reimbursed_reason TEXT DEFAULT NULL");
}
if (!$hasColumn('settings', 'stats_net_cost')) {
    $migrate("ALTER TABLE settings ADD COLUMN stats_net_cost INTEGER NOT NULL DEFAULT 0");
}
