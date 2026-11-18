<?php
/*
  Reminders for shared subscriptions (issue #59).

  A shared subscription's reminders always go to its owner. When its payer
  label is linked to another account of the household (household.
  linked_user_id, see includes/households.php), that account gets them too:
  at its own send time, through its own channels and templates, with prices
  in its own currency (wallos_shared_subscriptions() maps them).

  The owner's delivery markers live on the subscription row
  (last_notified_payment, last_cancel_notified_for, intro_notified_for) and
  in notification_log. A linked payer's are kept apart, per recipient, in
  notification_recipient_log, so neither marks the other's reminder as sent.

  Offsets come from the subscription; when it has none, the recipient's own
  default reminder days and overdue repeats apply.
*/

require_once __DIR__ . '/schedule.php';
require_once __DIR__ . '/messages.php';
require_once __DIR__ . '/../households.php';
require_once __DIR__ . '/../intro_pricing.php';
require_once __DIR__ . '/../cancellation_deadline.php';

/**
 * Active shared subscriptions whose payer label is linked to $recipientId.
 *
 * @return array[] wallos_shared_subscriptions() rows
 */
function wallos_linked_shared_subscriptions(SQLite3 $db, $recipientId)
{
    $rows = [];
    foreach (wallos_shared_subscriptions($db, $recipientId, true) as $row) {
        if ((int) ($row['shared_payer_linked_user_id'] ?? 0) === (int) $recipientId) {
            $rows[] = $row;
        }
    }

    return $rows;
}

/**
 * What the recipient was already sent, by subscription and due date (or
 * marker).
 *
 * @return array [subscription_id][due_date] => int[] offsets
 */
function wallos_recipient_log_load(SQLite3 $db, $recipientId, $kind)
{
    $stmt = $db->prepare('SELECT subscription_id, due_date, "offset" FROM notification_recipient_log WHERE user_id = :userId AND kind = :kind');
    $stmt->bindValue(':userId', (int) $recipientId, SQLITE3_INTEGER);
    $stmt->bindValue(':kind', $kind, SQLITE3_TEXT);
    $result = $stmt->execute();
    $log = [];
    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $log[(int) $row['subscription_id']][$row['due_date']][] = (int) $row['offset'];
    }

    return $log;
}

/**
 * Records what was delivered to the recipient. A duplicate is ignored.
 *
 * @param array $entries each ['subscription_id', 'owner_user_id', 'due_date', 'offset']
 */
function wallos_recipient_log_record(SQLite3 $db, $recipientId, $kind, array $entries, $sentAt = null)
{
    $sentAt = $sentAt ?? gmdate('Y-m-d H:i:s');
    $stmt = $db->prepare('INSERT OR IGNORE INTO notification_recipient_log (user_id, owner_user_id, subscription_id, kind, due_date, "offset", sent_at)
        VALUES (:userId, :ownerId, :subscriptionId, :kind, :dueDate, :offset, :sentAt)');
    if ($stmt === false) {
        return false;
    }
    foreach ($entries as $entry) {
        $stmt->bindValue(':userId', (int) $recipientId, SQLITE3_INTEGER);
        $stmt->bindValue(':ownerId', (int) $entry['owner_user_id'], SQLITE3_INTEGER);
        $stmt->bindValue(':subscriptionId', (int) $entry['subscription_id'], SQLITE3_INTEGER);
        $stmt->bindValue(':kind', $kind, SQLITE3_TEXT);
        $stmt->bindValue(':dueDate', (string) $entry['due_date'], SQLITE3_TEXT);
        $stmt->bindValue(':offset', (int) ($entry['offset'] ?? 0), SQLITE3_INTEGER);
        $stmt->bindValue(':sentAt', $sentAt, SQLITE3_TEXT);
        if ($stmt->execute() === false) {
            return false;
        }
        $stmt->reset();
    }

    return true;
}

/**
 * Drops the recipient's payment log rows for payments long past.
 */
function wallos_recipient_log_prune(SQLite3 $db, $recipientId, $today)
{
    $cutoff = (new DateTime($today))->modify('-400 days')->format('Y-m-d');
    $stmt = $db->prepare("DELETE FROM notification_recipient_log WHERE user_id = :userId AND kind = 'payment' AND due_date < :cutoff
        AND NOT EXISTS (SELECT 1 FROM subscriptions s WHERE s.id = notification_recipient_log.subscription_id
            AND substr(s.next_payment, 1, 10) = notification_recipient_log.due_date)");
    $stmt->bindValue(':userId', (int) $recipientId, SQLITE3_INTEGER);
    $stmt->bindValue(':cutoff', $cutoff, SQLITE3_TEXT);
    $stmt->execute();
}

/**
 * A reminder entry for a shared row: the price and currency are already the
 * recipient's (see wallos_shared_subscriptions()); category and payer are
 * the owner's names.
 */
function wallos_linked_notification_entry(array $row, array $lookups, $days, array $extra = [])
{
    $row['price'] = round((float) $row['price'], 2);

    return wallos_notification_entry($row, $lookups, $days, array_merge([
        'category' => (string) ($row['shared_category_name'] ?? ''),
        'payer' => (string) ($row['shared_payer_name'] ?? ''),
    ], $extra));
}

/**
 * The payment reminders due today for the shared subscriptions the
 * recipient is the linked payer of.
 *
 * @param array $settings the recipient's notification_settings row
 * @param array $lookups  the recipient's wallos_notification_lookups()
 * @return array [entries (one group), pending: key => [subscription_id, owner_user_id, due_date, offsets]]
 */
function wallos_collect_linked_payment_reminders(SQLite3 $db, $recipientId, array $settings, $today, array $lookups)
{
    $defaultDays = wallos_default_reminder_days($settings);
    $overdueOffsets = wallos_overdue_offsets($settings['repeat_every'] ?? 0, $settings['repeat_max'] ?? 0);
    $log = wallos_recipient_log_load($db, $recipientId, 'payment');

    $entries = [];
    $pending = [];
    foreach (wallos_linked_shared_subscriptions($db, $recipientId) as $row) {
        if ((int) $row['notify'] !== 1) {
            continue;
        }
        $due = wallos_payment_reminder_due($row, $today, $defaultDays, $overdueOffsets, $log, null);
        if ($due === null) {
            continue;
        }
        [$dueDate, $daysUntil, $dueOffsets] = $due;

        $key = 'payment:' . (int) $row['id'];
        $entries[] = wallos_linked_notification_entry($row, $lookups, $daysUntil, ['key' => $key]);
        $pending[$key] = [
            'subscription_id' => (int) $row['id'],
            'owner_user_id' => (int) $row['user_id'],
            'due_date' => $dueDate,
            'offsets' => $dueOffsets,
        ];
    }

    return [$entries, $pending];
}

/**
 * Logs the delivered linked payment reminders.
 */
function wallos_record_linked_payment_reminders(SQLite3 $db, $recipientId, array $pending, array $delivered)
{
    $entries = [];
    foreach ($pending as $key => $reminder) {
        if (empty($delivered[$key])) {
            continue;
        }
        foreach ($reminder['offsets'] as $offset) {
            $entries[] = [
                'subscription_id' => $reminder['subscription_id'],
                'owner_user_id' => $reminder['owner_user_id'],
                'due_date' => $reminder['due_date'],
                'offset' => $offset,
            ];
        }
    }

    return wallos_recipient_log_record($db, $recipientId, 'payment', $entries);
}

/**
 * The cancellation, free-trial and promo reminders due today for the shared
 * subscriptions the recipient is the linked payer of.
 *
 * The owner's markers on the row are replaced by the recipient's own before
 * the usual checks run, so the owner's reminder never counts as the
 * recipient's.
 *
 * @return array [entries (one group), markers: ['cancel' => id => [marker, owner], 'intro' => id => [marker, owner]]]
 */
function wallos_collect_linked_cancellation_reminders(SQLite3 $db, $recipientId, $today, array $lookups)
{
    $cancelLog = wallos_recipient_log_load($db, $recipientId, 'cancel');
    $introLog = wallos_recipient_log_load($db, $recipientId, 'intro');

    $entries = [];
    $markers = ['cancel' => [], 'intro' => []];
    foreach (wallos_linked_shared_subscriptions($db, $recipientId) as $row) {
        $id = (int) $row['id'];
        $owner = (int) $row['user_id'];

        // Cancellation notice: recurring subscriptions only.
        $mode = $row['cancel_notice_mode'] ?? null;
        $hasNotice = $mode === 'before_renewal'
            || (!empty($row['cancellation_date']) && ($mode === null || $mode === '' || $mode === 'fixed'));
        if ((int) $row['cycle'] !== 5 && $hasNotice) {
            $sent = array_keys($cancelLog[$id] ?? []);
            $deadline = wallos_cancel_deadline($row);
            $marker = null;
            if ($deadline !== null) {
                if (in_array($deadline['cancel_by'], $sent, true)) {
                    $marker = $deadline['cancel_by'];
                } elseif (in_array('lead:' . $deadline['cancel_by'], $sent, true)) {
                    $marker = 'lead:' . $deadline['cancel_by'];
                }
            }
            $due = wallos_cancellation_due(array_merge($row, ['last_cancel_notified_for' => $marker]), $today);
            if ($due !== null) {
                if ($due['renewal'] !== null) {
                    $note = " (cancel by " . $due['cancel_by'] . " to avoid the renewal on " . $due['renewal'] . ")";
                } elseif ($due['stage'] === 'lead') {
                    $note = " (cancel by " . $due['cancel_by'] . ")";
                } else {
                    $note = "";
                }
                $markers['cancel'][$id] = [$due['marker'], $owner];
                $entries[] = wallos_linked_notification_entry($row, $lookups, wallos_days_between($today, $due['cancel_by']), [
                    'cancel_by' => $due['cancel_by'],
                    'deadline_note' => $note,
                    'key' => wallos_notification_key('cancel', $id),
                ]);
            }
        }

        // Free trials and promos.
        if ($row['intro_type'] === null && $row['intro_converted_on'] === null) {
            continue;
        }
        $sent = array_keys($introLog[$id] ?? []);
        $convertedMarker = $row['intro_converted_on'] !== null ? 'converted:' . substr((string) $row['intro_converted_on'], 0, 10) : null;
        $introMarker = null;
        if ($convertedMarker !== null && in_array($convertedMarker, $sent, true)) {
            $introMarker = $convertedMarker;
        } elseif (!empty($row['intro_end_date']) && in_array(substr((string) $row['intro_end_date'], 0, 10), $sent, true)) {
            $introMarker = substr((string) $row['intro_end_date'], 0, 10);
        }
        $introRow = array_merge($row, ['intro_notified_for' => $introMarker]);
        $symbol = $lookups['currencies'][$row['currency_id']]['symbol'] ?? '';
        if (wallos_intro_ending_due($introRow, $today)) {
            $marker = $row['intro_end_date'];
            $note = ($row['intro_type'] === 'trial' ? " (free trial ends " : " (intro price ends ")
                . $row['intro_end_date'] . ", then " . $row['price'] . $symbol . ")";
            $cancelBy = $row['intro_end_date'];
            $price = wallos_intro_price($row) . $symbol;
        } elseif (wallos_intro_converted_due($introRow, $today)) {
            $marker = $convertedMarker;
            $note = " (intro period ended " . $row['intro_converted_on'] . "; the regular price now applies)";
            $cancelBy = $row['intro_converted_on'];
            $price = $row['price'] . $symbol;
        } else {
            continue;
        }
        $markers['intro'][$id] = [$marker, $owner];
        $entries[] = wallos_linked_notification_entry($row, $lookups, wallos_days_between($today, $cancelBy), [
            'price' => $price,
            'cancel_by' => $cancelBy,
            'deadline_note' => $note,
            'key' => wallos_notification_key('intro', $id),
        ]);
    }

    return [$entries, $markers];
}

/**
 * Logs the delivered linked cancellation and intro reminders.
 */
function wallos_record_linked_cancellation_reminders(SQLite3 $db, $recipientId, array $markers, array $delivered)
{
    $ok = true;
    foreach (['cancel', 'intro'] as $type) {
        $entries = [];
        foreach ($markers[$type] as $subscriptionId => [$marker, $owner]) {
            if (!empty($delivered[wallos_notification_key($type, $subscriptionId)])) {
                $entries[] = ['subscription_id' => $subscriptionId, 'owner_user_id' => $owner, 'due_date' => $marker, 'offset' => 0];
            }
        }
        $ok = wallos_recipient_log_record($db, $recipientId, $type, $entries) && $ok;
    }

    return $ok;
}

/**
 * The one message context for a recipient's linked reminders.
 *
 * @param array $recipient the user row (username, email)
 */
function wallos_linked_notification_contexts(array $entries, array $recipient, $kind)
{
    if ($entries === []) {
        return [];
    }

    return wallos_notification_contexts(
        [0 => $entries],
        [0 => ['name' => (string) ($recipient['username'] ?? ''), 'email' => (string) ($recipient['email'] ?? '')]],
        $recipient,
        $kind
    );
}
