<?php
/*
  Cancellation, free-trial and promo reminders.

  Runs every 15 minutes (see the cronjobs file) and processes each account
  once a day at its "Send at" time, like sendnotifications.php. Run from the
  admin page, or with --force on the command line, every account is
  processed now.

  Which reminders are due: wallos_cancellation_due() and the intro helpers,
  collected by wallos_collect_cancellation_reminders(). Every channel the
  payment reminders use goes out here too, through the same dispatcher.
*/

require_once 'validate.php';
require_once __DIR__ . '/../../includes/connect_endpoint_crontabs.php';
require_once __DIR__ . '/../../includes/notifications/dispatch.php';
require_once __DIR__ . '/../../includes/notifications/schedule.php';
require_once __DIR__ . '/../../includes/currency_formatter.php';

require_once __DIR__ . '/../../libs/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/../../libs/PHPMailer/SMTP.php';
require_once __DIR__ . '/../../libs/PHPMailer/Exception.php';

require 'settimezone.php';

$force = php_sapi_name() !== 'cli' || in_array('--force', $argv ?? [], true);
$containerTimezone = date_default_timezone_get();

$users = [];
$result = $db->query('SELECT id, username, email FROM user');
while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
    $users[] = $row;
}

$settingsByUser = [];
$result = $db->query('SELECT * FROM notification_settings');
while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
    $settingsByUser[(int) $row['user_id']] = $row;
}

foreach ($users as $owner) {
    $userId = (int) $owner['id'];
    $settings = $settingsByUser[$userId] ?? ['days' => 1, 'send_time' => '09:00'];

    date_default_timezone_set(wallos_notification_timezone($settings)->getName());
    $now = new DateTime('now');
    $today = $now->format('Y-m-d');

    if (!$force && !wallos_notification_slot_due($settings['send_time'] ?? '09:00', $settings['last_cancellation_run'] ?? null, $now)) {
        date_default_timezone_set($containerTimezone);
        continue;
    }

    if (php_sapi_name() !== 'cli') {
        echo "For user: " . htmlspecialchars($owner['username']) . "<br />";
    }

    try {
        $env = new WallosNotifyEnv($db, $userId);
        $channels = wallos_load_notification_channels($env, 'cancellation');

        if ($channels !== []) {
            $lookups = wallos_notification_lookups($db, $userId);
            CurrencyFormatter::useCurrencies($lookups['currencies']);

            [$entries, $markers] = wallos_collect_cancellation_reminders($db, $userId, $today, $lookups);

            if ($entries !== []) {
                $contexts = wallos_notification_contexts($entries, $lookups['household'], $owner, 'cancellation');
                $delivered = wallos_dispatch_notifications($env, $channels, 'cancellation', $contexts, wallos_notification_templates_load($db, $userId));

                // Mark what at least one channel delivered; the others are
                // tried again on the next day's run.
                wallos_record_cancellation_reminders($db, $userId, $markers, $delivered);
            } elseif (php_sapi_name() !== 'cli') {
                echo "Nothing to notify.<br />";
            }
        } elseif (php_sapi_name() !== 'cli') {
            echo "Notifications are disabled. No need to run.<br />";
        }
    } catch (\Throwable $e) {
        // One account failing must not stop the others.
        echo "Error processing cancellation reminders for user " . $userId . ": " . $e->getMessage() . "<br />\n";
    }

    wallos_mark_notification_run($db, $userId, 'last_cancellation_run', $today);
    date_default_timezone_set($containerTimezone);
}
