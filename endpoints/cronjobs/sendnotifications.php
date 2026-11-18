<?php
/*
  Payment reminders.

  Runs every 15 minutes (see the cronjobs file). Each account is processed
  once a day, on the first run at or after its "Send at" time in its own time
  zone (Settings -> Notifications). Run from the admin page, or with --force
  on the command line, every account is processed now.

  Which reminders are due: includes/notifications/schedule.php.
  How they are written and sent: includes/notifications/.
*/

require_once 'validate.php';
require_once __DIR__ . '/../../includes/connect_endpoint_crontabs.php';
require_once __DIR__ . '/../../includes/notifications/dispatch.php';
require_once __DIR__ . '/../../includes/notifications/schedule.php';

require_once __DIR__ . '/../../libs/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/../../libs/PHPMailer/SMTP.php';
require_once __DIR__ . '/../../libs/PHPMailer/Exception.php';

require_once __DIR__ . '/../../includes/currency_formatter.php';
require_once __DIR__ . '/../../includes/budget_period_calculations.php';

require 'settimezone.php';

$force = php_sapi_name() !== 'cli' || in_array('--force', $argv ?? [], true);

// One run at a time: an overlapping run would read the same unsent reminders.
$notificationLock = wallos_acquire_notification_lock('payment');
if ($notificationLock === false) {
    echo "Another payment reminder run is in progress.\n";
    exit;
}
$containerTimezone = date_default_timezone_get();

if (php_sapi_name() == 'cli') {
    echo "\n" . date('Y-m-d H:i:s') . "\n";
} else {
    echo "On Timezone: " . $containerTimezone . "<br /><br />";
}

$hasPeriodSummaryColumn = (bool) $db->querySingle("SELECT 1 FROM pragma_table_info('notification_settings') WHERE name = 'period_summary_at_period_start'");

$users = [];
$result = $db->query('SELECT id, username, email FROM user');
while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
    $users[] = $row;
}


foreach ($users as $owner) {
    $userId = (int) $owner['id'];
    $settings = wallos_notification_settings_row($db, $userId);

    date_default_timezone_set(wallos_notification_timezone($settings)->getName());
    $now = new DateTime('now');
    $today = $now->format('Y-m-d');

    // The cheap check first: most runs of the day have nobody due.
    if (!$force && !wallos_notification_slot_due($settings['send_time'] ?? '09:00', $settings['last_payment_run'] ?? null, $now)) {
        date_default_timezone_set($containerTimezone);
        continue;
    }

    if (php_sapi_name() !== 'cli') {
        echo "For user: " . htmlspecialchars($owner['username']) . "<br /><br />";
    }

    $complete = true;
    try {
        $env = new WallosNotifyEnv($db, $userId);
        $channels = wallos_load_notification_channels($env, 'payment');

        if ($channels !== []) {
            $lookups = wallos_notification_lookups($db, $userId);
            CurrencyFormatter::useCurrencies($lookups['currencies']);

            // The pay-period summary line.
            $stmt = $db->prepare('SELECT main_currency, period_budget, budget_period_type, budget_period_anchor_date FROM user WHERE id = :userId');
            $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
            $budget = $stmt->execute()->fetchArray(SQLITE3_ASSOC) ?: [];

            $budgetPeriodType = sanitizeBudgetPeriodType($budget['budget_period_type'] ?? 'monthly');
            $budgetAnchor = sanitizeBudgetAnchorDate($budget['budget_period_anchor_date'] ?? getDefaultBudgetAnchorDate());
            $activePeriod = getActiveBudgetPeriod($now, $budgetPeriodType, $budgetAnchor);
            $isPeriodStart = $activePeriod['start']->format('Y-m-d') === $today;

            $stmt = $db->prepare('SELECT price, currency_id, next_payment, cycle, frequency, inactive, auto_renew, start_date, billing_anchor_day, active_months, end_date, intro_type, intro_price, intro_end_date FROM subscriptions WHERE user_id = :userId AND inactive = 0');
            $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
            $result = $stmt->execute();
            $periodSubscriptions = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $periodSubscriptions[] = $row;
            }

            $amountNeeded = computeAmountNeededInPeriod($periodSubscriptions, $now, $activePeriod['end'], $db, $userId);
            $mainCurrency = $lookups['currencies'][$budget['main_currency'] ?? 0] ?? ['code' => 'USD', 'symbol' => '$'];
            $periodSummaryLine = translate('amount_for_pay_period', $i18n) . ": "
                . wallos_notification_format_price($amountNeeded, $mainCurrency['code'], $mainCurrency['symbol']);
            if (!empty($budget['period_budget']) && $budget['period_budget'] > 0) {
                $remaining = max(0, $budget['period_budget'] - $amountNeeded);
                $periodSummaryLine .= " | " . translate('remaining', $i18n) . ": "
                    . wallos_notification_format_price($remaining, $mainCurrency['code'], $mainCurrency['symbol']);
            }
            $summaryOnly = $hasPeriodSummaryColumn && (int) ($settings['period_summary_at_period_start'] ?? 0) === 1 && $isPeriodStart;

            // Which subscriptions have a reminder due.
            [$entries, $pending] = wallos_collect_payment_reminders($db, $userId, $settings, $today, $lookups);
            foreach ($pending as $reminder) {
                echo "Reminder due: subscription " . $reminder['subscription_id'] . " (" . $reminder['due_date'] . ")<br />\n";
            }

            if ($entries === [] && $summaryOnly && $lookups['household'] !== []) {
                $entries[array_key_first($lookups['household'])] = [];
            }

            if ($entries !== []) {
                $contexts = wallos_notification_contexts($entries, $lookups['household'], $owner, 'payment', [
                    'period_summary' => $periodSummaryLine,
                    'include_period_summary' => $summaryOnly,
                ]);
                $delivered = wallos_dispatch_notifications($env, $channels, 'payment', $contexts, wallos_notification_templates_load($db, $userId));

                // Only what a channel delivered is marked as sent; the rest is
                // tried again on tomorrow's run.
                $recorded = wallos_record_payment_reminders($db, $userId, $pending, $delivered);
                // Anything undelivered (or not recorded) keeps the day open
                // for a retry on the next run.
                $complete = $recorded && array_diff_key($pending, $delivered) === [];
            } elseif (php_sapi_name() !== 'cli') {
                echo "Nothing to notify.<br />";
            }

            wallos_notification_log_prune($db, $userId, $today);
        } elseif (php_sapi_name() !== 'cli') {
            echo "Notifications are disabled. No need to run.<br />";
        }
    } catch (\Throwable $e) {
        // One account failing must not stop the others.
        $complete = false;
        echo "Error processing payment reminders for user " . $userId . ": " . $e->getMessage() . "<br />\n";
    }

    wallos_mark_notification_run($db, $userId, 'last_payment_run', $today, $complete);
    date_default_timezone_set($containerTimezone);
}
