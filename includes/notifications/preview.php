<?php
/*
  The context the template preview renders with: the account's own next few
  subscriptions, so the preview shows real names and prices. An account with
  none gets two sample rows.
*/

require_once __DIR__ . '/messages.php';
require_once __DIR__ . '/schedule.php';
require_once __DIR__ . '/../cancellation_deadline.php';

/**
 * @param string $kind 'payment' or 'cancellation'
 * @return array a payer context, as the crons build it
 */
function wallos_notification_preview_context(SQLite3 $db, $userId, $kind, $today = null)
{
    if ($today === null) {
        // The account's own calendar, as the crons use.
        $stmt = $db->prepare('SELECT timezone FROM notification_settings WHERE user_id = :userId');
        $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
        $settingsRow = $stmt->execute()->fetchArray(SQLITE3_ASSOC) ?: [];
        $today = (new DateTime('now', wallos_notification_timezone($settingsRow)))->format('Y-m-d');
    }
    $lookups = wallos_notification_lookups($db, $userId);
    if (!class_exists('CurrencyFormatter')) {
        require_once __DIR__ . '/../currency_formatter.php';
    }
    CurrencyFormatter::useCurrencies($lookups['currencies']);

    // One payer's digest, as the cron sends it: the payer of the next payment.
    $stmt = $db->prepare('SELECT * FROM subscriptions WHERE user_id = :userId AND inactive = 0
        AND payer_user_id = (SELECT payer_user_id FROM subscriptions WHERE user_id = :userId AND inactive = 0 ORDER BY next_payment ASC, id ASC LIMIT 1)
        ORDER BY next_payment ASC, id ASC LIMIT 3');
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    $entries = [];
    $payerId = null;
    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $days = wallos_parse_date($row['next_payment']) !== null ? wallos_days_between($today, $row['next_payment']) : 0;
        $extra = [];
        if ($kind === 'cancellation') {
            $deadline = wallos_cancel_deadline($row);
            $cancelBy = $deadline['cancel_by'] ?? $row['next_payment'];
            // Cancellation reminders count down to the cancel-by date.
            if ($deadline !== null) {
                $days = wallos_days_between($today, $cancelBy);
            }
            $extra = [
                'cancel_by' => $cancelBy,
                'deadline_note' => ' (cancel by ' . $cancelBy . ')',
            ];
        }
        $entries[] = wallos_notification_entry($row, $lookups, $days, $extra);
        $payerId = $payerId ?? $row['payer_user_id'];
    }

    if ($entries === []) {
        foreach ([['Streaming', 12.99, 3], ['Cloud storage', 2.99, 0]] as $index => [$name, $price, $days]) {
            $entries[] = [
                'id' => $index + 1, 'name' => $name, 'price' => $price . '$', 'formatted_price' => '$ ' . number_format($price, 2),
                'currency' => 'US Dollar', 'currency_symbol' => '$', 'category' => 'Entertainment', 'payer' => '',
                'date' => (new DateTime($today))->modify('+' . $days . ' days')->format('Y-m-d'), 'days' => $days,
                'days_text' => wallos_notification_days_text($days), 'url' => '', 'notes' => '',
                'cancel_by' => $today, 'deadline_note' => $kind === 'cancellation' ? ' (cancel by ' . $today . ')' : '',
            ];
        }
    }

    $owner = ['username' => '', 'email' => ''];
    $stmt = $db->prepare('SELECT username, email FROM user WHERE id = :userId');
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
    $ownerRow = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if ($ownerRow) {
        $owner = $ownerRow;
    }

    $payerKey = $payerId ?? (array_key_first($lookups['household']) ?? 0);
    $contexts = wallos_notification_contexts([$payerKey => $entries], $lookups['household'], $owner, $kind, [
        'period_summary' => 'Amount for this pay period: $ 15.98',
        'include_period_summary' => false,
    ]);

    return $contexts[0];
}
