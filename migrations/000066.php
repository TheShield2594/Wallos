<?php
/*
  Shared households (see includes/households.php).

  households         id, name, owner_user_id
  household_members  household_id, user_id, role ('viewer' | 'editor'),
                     status ('pending' | 'active')
  subscriptions.shared_household_id  NULL = private

  Safe to run twice.
*/

$db->exec("CREATE TABLE IF NOT EXISTS households (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    owner_user_id INTEGER NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");
$db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_households_owner ON households (owner_user_id)");

$db->exec("CREATE TABLE IF NOT EXISTS household_members (
    household_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    role TEXT NOT NULL DEFAULT 'viewer' CHECK (role IN ('viewer', 'editor')),
    status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'active')),
    invited_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (household_id, user_id)
)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_household_members_user ON household_members (user_id, status)");

$columnQuery = $db->query("SELECT * FROM pragma_table_info('subscriptions') WHERE name = 'shared_household_id'");
if ($columnQuery->fetchArray(SQLITE3_ASSOC) === false) {
    $db->exec("ALTER TABLE subscriptions ADD COLUMN shared_household_id INTEGER DEFAULT NULL");
}
$db->exec("CREATE INDEX IF NOT EXISTS idx_subscriptions_shared_household ON subscriptions (shared_household_id)");
