<?php
/*
  Price and plan changes (see includes/price_changes.php).

  subscriptions.tier_name   optional plan or tier label ("Ultimate").

  subscription_price_changes  one row per edit that changed the price,
                              currency, cycle or frequency:
    old_price, old_currency_id, old_cycle, old_frequency, old_tier
    new_price, currency_id,     new_cycle, new_frequency, new_tier
    old_monthly, new_monthly    both spread per month and in currency_id
                                (the old amount converted at the rates of
                                changed_at), so mixed cycles and currencies
                                compare directly.
    reason                      'tier' (the user changed plan) or
                                'provider' (the provider changed the price).

  Safe to run twice: a failed statement throws, so the runner does not
  record the migration and the next run finishes it.
*/

$migrate = function ($sql) use ($db) {
    if ($db->exec($sql) === false) {
        throw new RuntimeException('Migration 000068 failed: ' . $db->lastErrorMsg());
    }
};

$columnQuery = $db->query("SELECT * FROM pragma_table_info('subscriptions') WHERE name = 'tier_name'");
if ($columnQuery === false) {
    throw new RuntimeException('Migration 000068 failed: ' . $db->lastErrorMsg());
}
if ($columnQuery->fetchArray(SQLITE3_ASSOC) === false) {
    $migrate("ALTER TABLE subscriptions ADD COLUMN tier_name TEXT DEFAULT NULL");
}

$migrate("CREATE TABLE IF NOT EXISTS subscription_price_changes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    subscription_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    changed_at DATE NOT NULL,
    old_price REAL,
    new_price REAL,
    old_currency_id INTEGER,
    currency_id INTEGER,
    old_cycle INTEGER,
    new_cycle INTEGER,
    old_frequency INTEGER,
    new_frequency INTEGER,
    old_monthly REAL,
    new_monthly REAL,
    old_tier TEXT,
    new_tier TEXT,
    reason TEXT NOT NULL DEFAULT 'tier' CHECK (reason IN ('tier', 'provider')),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");
$migrate("CREATE INDEX IF NOT EXISTS idx_price_changes_subscription ON subscription_price_changes (subscription_id, changed_at)");
$migrate("CREATE INDEX IF NOT EXISTS idx_price_changes_user ON subscription_price_changes (user_id)");
