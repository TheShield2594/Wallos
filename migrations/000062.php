<?php
/*
  Recurrence, payment ledger and fixed-term plans.

  subscriptions
    billing_anchor_day  The day of the month a monthly or yearly subscription
                        bills on. A short month clamps to its last day and the
                        next month returns to the anchor, so a bill on the 31st
                        goes Jan 31, Feb 28, Mar 31 (see includes/recurrence.php).
    renew_from_action   Manual renewals restart the cycle on the day the user
                        marks the payment as paid instead of following the
                        original schedule.
    variable_amount     The price is an estimate; each cycle asks for the
                        amount actually paid.
    end_date            Final payment date of a fixed-term plan (inclusive).
    total_payments      Number of payments in a fixed-term plan.
    payments_made       Payments of the plan that have already happened.
    ended_reason        'completed' when a plan ran its course. The row is then
                        inactive but is not counted as a saving.

  subscription_payments
    One row per charge: what was paid for which due date. The UNIQUE key on
    (subscription_id, due_date) keeps the renewal cron idempotent.

  settings.require_payment_confirmation
    Cycles the cron advances on its own are listed as "Unconfirmed" on the
    dashboard until the user confirms them.

  Safe to run twice.
*/

$subscriptionColumns = [
    'billing_anchor_day' => 'INTEGER DEFAULT NULL',
    'renew_from_action' => 'INTEGER DEFAULT 0',
    'variable_amount' => 'INTEGER DEFAULT 0',
    'end_date' => 'DATE DEFAULT NULL',
    'total_payments' => 'INTEGER DEFAULT NULL',
    'payments_made' => 'INTEGER DEFAULT 0',
    'ended_reason' => 'TEXT DEFAULT NULL',
];

$addedAnchor = false;
foreach ($subscriptionColumns as $column => $definition) {
    $columnQuery = $db->query("SELECT * FROM pragma_table_info('subscriptions') WHERE name = '$column'");
    if ($columnQuery->fetchArray(SQLITE3_ASSOC) === false) {
        $db->exec("ALTER TABLE subscriptions ADD COLUMN $column $definition");
        if ($column === 'billing_anchor_day') {
            $addedAnchor = true;
        }
    }
}

$db->exec("CREATE TABLE IF NOT EXISTS subscription_payments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    subscription_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    due_date DATE NOT NULL,
    paid_date DATE DEFAULT NULL,
    amount REAL DEFAULT NULL,
    currency_id INTEGER DEFAULT NULL,
    status TEXT NOT NULL DEFAULT 'paid',
    source TEXT NOT NULL DEFAULT 'auto',
    note TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (subscription_id, due_date)
)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_subscription_payments_user ON subscription_payments (user_id, due_date)");

$columnQuery = $db->query("SELECT * FROM pragma_table_info('settings') WHERE name = 'require_payment_confirmation'");
if ($columnQuery->fetchArray(SQLITE3_ASSOC) === false) {
    $db->exec("ALTER TABLE settings ADD COLUMN require_payment_confirmation BOOLEAN DEFAULT 0");
}

// Backfill the anchor day of monthly (3) and yearly (4) subscriptions.
//
// The day of next_payment is right for most rows. Two patterns are not:
//  - next_payment sits on the last day of a short month while the start date
//    is later in the month (Jan 31 -> Feb 28): the anchor is the start day.
//  - the old DateInterval arithmetic rolled the 29th-31st over into the next
//    month (Jan 31 + 1 month = Mar 3) and the row stayed on the 1st-3rd since:
//    the anchor is the start day too, so the next renewal returns to it.
// next_payment itself is left alone; the next renewal lands on the anchor.
if ($addedAnchor) {
    $result = $db->query("SELECT id, next_payment, start_date FROM subscriptions WHERE cycle IN (3, 4) AND next_payment IS NOT NULL");
    $updates = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $next = DateTime::createFromFormat('!Y-m-d', substr((string) $row['next_payment'], 0, 10));
        if ($next === false) {
            continue;
        }
        $anchor = (int) $next->format('j');
        $start = !empty($row['start_date']) ? DateTime::createFromFormat('!Y-m-d', substr((string) $row['start_date'], 0, 10)) : false;
        if ($start !== false) {
            $startDay = (int) $start->format('j');
            $isMonthEnd = $anchor === (int) $next->format('t');
            $looksDrifted = $startDay >= 29 && $anchor <= 3;
            if ($startDay > $anchor && ($isMonthEnd || $looksDrifted)) {
                $anchor = $startDay;
            }
        }
        $updates[(int) $row['id']] = $anchor;
    }

    $stmt = $db->prepare("UPDATE subscriptions SET billing_anchor_day = :anchor WHERE id = :id");
    foreach ($updates as $id => $anchor) {
        $stmt->bindValue(':anchor', $anchor, SQLITE3_INTEGER);
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $stmt->execute();
        $stmt->reset();
    }
}
