<?php
/*
  When reminders go out (issues #39 and #40).

  - Each account picks a local send time (15-minute steps) and optionally a
    time zone. The crons run every 15 minutes and process an account once a
    day, on the first run at or after its send time
    (wallos_notification_slot_due()). A run the container missed is caught up
    by the next one the same day; a whole day missed is caught up by the
    reminder windows below.

  - A subscription can have several reminders ("7, 3 and 0 days before").
    Each offset opens a window that lasts until the payment; the first run
    inside it sends a reminder, and notification_log records the offset so
    it is not sent again. Missing the 7-day run sends one reminder on the
    next day and logs 7, rather than a burst later.

  - Subscriptions that do not renew automatically and are still unpaid after
    their date can be reminded again every N days, at most M times: those
    are negative offsets (-N, -2N, ...).
*/

const WALLOS_REMINDER_MAX_OFFSET = 365;
const WALLOS_REMINDER_MAX_OFFSETS = 10;
const WALLOS_CANCEL_LEAD_CHOICES = [0, 1, 3, 7, 14, 30, 60];
const WALLOS_NOTIFICATION_MAX_ATTEMPTS = 4;

/**
 * The account's notification_settings row, read when its turn comes (a
 * change saved while the cron works on other accounts is honoured).
 */
function wallos_notification_settings_row(SQLite3 $db, $userId)
{
    $stmt = $db->prepare('SELECT * FROM notification_settings WHERE user_id = :userId');
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;

    return $row ?: ['days' => 1, 'send_time' => '09:00'];
}

/**
 * Parses a list of reminder offsets ("7,3,0", [7, 3, 0]) into unique,
 * descending non-negative integers. Invalid entries are dropped.
 *
 * @param mixed $raw
 * @return int[]
 */
function wallos_parse_reminder_days($raw)
{
    if ($raw === null || $raw === '') {
        return [];
    }
    $items = is_array($raw) ? $raw : explode(',', (string) $raw);
    $days = [];
    foreach ($items as $item) {
        $item = is_string($item) ? trim($item) : $item;
        if ($item === '' || $item === null || !is_numeric($item) || (int) $item != $item) {
            continue;
        }
        $value = (int) $item;
        if ($value >= 0 && $value <= WALLOS_REMINDER_MAX_OFFSET) {
            $days[$value] = $value;
        }
    }
    rsort($days);

    return array_slice(array_values($days), 0, WALLOS_REMINDER_MAX_OFFSETS);
}

/**
 * @param int[] $days
 * @return string|null "7,3,0", or null for an empty list
 */
function wallos_format_reminder_days(array $days)
{
    $days = wallos_parse_reminder_days($days);

    return $days === [] ? null : implode(',', $days);
}

/**
 * The account's default reminder offsets.
 *
 * @param array|null $settings a notification_settings row
 * @return int[]
 */
function wallos_default_reminder_days($settings)
{
    $days = wallos_parse_reminder_days($settings['reminder_days'] ?? null);
    if ($days !== []) {
        return $days;
    }

    return [max(0, (int) ($settings['days'] ?? 1))];
}

/**
 * The reminder offsets of one subscription: its own list, its single legacy
 * value, or the account default (notify_days_before = -1 or NULL).
 *
 * @param array $subscription
 * @param int[] $defaultDays
 * @return int[]
 */
function wallos_subscription_reminder_days(array $subscription, array $defaultDays)
{
    $own = wallos_parse_reminder_days($subscription['reminder_days'] ?? null);
    if ($own !== []) {
        return $own;
    }
    $single = array_key_exists('notify_days_before', $subscription) ? $subscription['notify_days_before'] : -1;
    // NULL (an API client sent '') never sent a reminder, and still doesn't.
    if ($single === null || $single === '') {
        return [];
    }
    if ((int) $single >= 0) {
        return [(int) $single];
    }

    return $defaultDays;
}

/**
 * The overdue repeat offsets (-N, -2N, ... -M*N), or none.
 *
 * @return int[]
 */
function wallos_overdue_offsets($repeatEvery, $repeatMax)
{
    $repeatEvery = (int) $repeatEvery;
    $repeatMax = min(52, (int) $repeatMax);
    if ($repeatEvery < 1 || $repeatMax < 1) {
        return [];
    }
    $offsets = [];
    for ($i = 1; $i <= $repeatMax; $i++) {
        $offsets[] = -$i * $repeatEvery;
    }

    return $offsets;
}

/**
 * Which reminder offsets are due now for one payment.
 *
 * Every offset whose window has opened ($offset >= $daysUntil) and that is
 * not logged yet. Non-negative offsets only apply while the payment is still
 * ahead (or today); overdue offsets only to a past payment that does not
 * renew automatically.
 *
 * Returns the offsets to log; empty when nothing is due. One message covers
 * them all.
 *
 * @param int   $daysUntil  days from today to the payment (negative = overdue)
 * @param int[] $offsets    the subscription's reminder offsets
 * @param int[] $overdue    overdue offsets (negative)
 * @param bool  $autoRenew
 * @param int[] $logged     offsets already sent for this payment
 * @return int[]
 */
function wallos_due_reminder_offsets($daysUntil, array $offsets, array $overdue, $autoRenew, array $logged)
{
    $daysUntil = (int) $daysUntil;
    $candidates = [];
    if ($daysUntil >= 0) {
        $candidates = $offsets;
    } elseif (!$autoRenew) {
        $candidates = $overdue;
    }

    $due = [];
    foreach ($candidates as $offset) {
        if ($offset >= $daysUntil && !in_array((int) $offset, $logged, true)) {
            $due[] = (int) $offset;
        }
    }

    return $due;
}

/**
 * The offsets already sent for a payment, from notification_log. The legacy
 * single-reminder marker (subscriptions.last_notified_payment) counts as
 * every offset whose window had opened by today.
 *
 * @param array       $loggedRows offsets from notification_log for this due date
 * @param string|null $lastNotifiedPayment
 * @param string      $dueDate
 * @param int         $daysUntil
 * @param int[]       $offsets
 * @return int[]
 */
function wallos_logged_offsets(array $loggedRows, $lastNotifiedPayment, $dueDate, $daysUntil, array $offsets)
{
    $logged = array_map('intval', $loggedRows);
    if ($logged === [] && $lastNotifiedPayment !== null && $lastNotifiedPayment === $dueDate) {
        foreach ($offsets as $offset) {
            if ($offset >= $daysUntil) {
                $logged[] = (int) $offset;
            }
        }
    }

    return $logged;
}

/**
 * Normalizes a send time to "HH:MM" on a 15-minute step; null when invalid.
 */
function wallos_normalize_send_time($value)
{
    if (!is_string($value) || !preg_match('/^(\d{1,2}):(\d{2})$/', trim($value), $match)) {
        return null;
    }
    $hour = (int) $match[1];
    $minute = (int) $match[2];
    if ($hour > 23 || $minute > 59 || $minute % 15 !== 0) {
        return null;
    }

    return sprintf('%02d:%02d', $hour, $minute);
}

/**
 * Whether a time zone name is one PHP knows.
 */
function wallos_valid_timezone($timezone)
{
    return is_string($timezone) && $timezone !== '' && in_array($timezone, DateTimeZone::listIdentifiers(), true);
}

/**
 * The account's time zone: its own setting, or the container's.
 */
function wallos_notification_timezone($settings)
{
    $timezone = $settings['timezone'] ?? null;
    if (wallos_valid_timezone($timezone)) {
        return new DateTimeZone($timezone);
    }

    return new DateTimeZone(date_default_timezone_get());
}

/**
 * Whether the account's daily run is due: at or after its send time, and
 * not run yet today (local date).
 *
 * @param string      $sendTime "HH:MM"
 * @param string|null $lastRun  local date of the last run, Y-m-d
 * @param DateTime    $localNow now in the account's time zone
 */
function wallos_notification_slot_due($sendTime, $lastRun, DateTime $localNow)
{
    $sendTime = wallos_normalize_send_time((string) $sendTime) ?? '09:00';
    if ($lastRun === $localNow->format('Y-m-d')) {
        return false;
    }

    return $localNow->format('H:i') >= $sendTime;
}

/**
 * Sent offsets for the given subscriptions and kind, keyed by
 * subscription id and due date.
 *
 * @return array [subscription_id][due_date] => int[]
 */
function wallos_notification_log_load(SQLite3 $db, $userId, $kind)
{
    $stmt = $db->prepare('SELECT subscription_id, due_date, "offset" FROM notification_log WHERE user_id = :userId AND kind = :kind');
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':kind', $kind, SQLITE3_TEXT);
    $result = $stmt->execute();
    $log = [];
    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $log[(int) $row['subscription_id']][$row['due_date']][] = (int) $row['offset'];
    }

    return $log;
}

/**
 * Records sent reminders. A duplicate is ignored.
 *
 * @param array $entries each ['subscription_id', 'due_date', 'offset']
 */
function wallos_notification_log_record(SQLite3 $db, $userId, $kind, array $entries, $sentAt = null)
{
    $sentAt = $sentAt ?? gmdate('Y-m-d H:i:s');
    $stmt = $db->prepare('INSERT OR IGNORE INTO notification_log (user_id, subscription_id, kind, due_date, "offset", sent_at)
        VALUES (:userId, :subscriptionId, :kind, :dueDate, :offset, :sentAt)');
    if ($stmt === false) {
        return false;
    }
    foreach ($entries as $entry) {
        $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
        $stmt->bindValue(':subscriptionId', (int) $entry['subscription_id'], SQLITE3_INTEGER);
        $stmt->bindValue(':kind', $kind, SQLITE3_TEXT);
        $stmt->bindValue(':dueDate', $entry['due_date'], SQLITE3_TEXT);
        $stmt->bindValue(':offset', (int) $entry['offset'], SQLITE3_INTEGER);
        $stmt->bindValue(':sentAt', $sentAt, SQLITE3_TEXT);
        if ($stmt->execute() === false) {
            return false;
        }
        $stmt->reset();
    }

    return true;
}

/**
 * Drops log rows for payments long past, so the table does not grow forever.
 */
function wallos_notification_log_prune(SQLite3 $db, $userId, $today)
{
    $cutoff = (new DateTime($today))->modify('-400 days')->format('Y-m-d');
    // Not the rows of a payment that is still the subscription's next one (an
    // unpaid, overdue payment): dropping them would re-send its repeats.
    $stmt = $db->prepare('DELETE FROM notification_log WHERE user_id = :userId AND due_date < :cutoff
        AND NOT EXISTS (SELECT 1 FROM subscriptions s WHERE s.id = notification_log.subscription_id
            AND s.user_id = notification_log.user_id AND substr(s.next_payment, 1, 10) = notification_log.due_date)');
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':cutoff', $cutoff, SQLITE3_TEXT);
    $stmt->execute();
}

/**
 * Whether payment reminders are muted for this payment: the subscription asks
 * to be quiet until its cancel-by date, and the payment falls on or before it.
 *
 * @param array       $subscription
 * @param string|null $cancelBy Y-m-d from wallos_cancel_deadline()
 */
function wallos_payment_reminder_muted(array $subscription, $cancelBy)
{
    if (empty($subscription['mute_payment_until_cancellation']) || $cancelBy === null || $cancelBy === '') {
        return false;
    }

    return substr((string) ($subscription['next_payment'] ?? ''), 0, 10) <= $cancelBy;
}

/**
 * The "due soon" state of a dashboard row (issue #40): the next payment is
 * inside the reminder window. Overdue is handled by the caller.
 *
 * @param int   $daysUntil
 * @param int[] $offsets
 */
function wallos_is_due_soon($daysUntil, array $offsets)
{
    if ($offsets === [] || $daysUntil < 0) {
        return false;
    }

    return $daysUntil <= max($offsets);
}

/**
 * Whole calendar days from $today to $date (negative when past).
 */
function wallos_days_between($today, $date)
{
    $from = new DateTime(substr((string) $today, 0, 10));
    $to = new DateTime(substr((string) $date, 0, 10));
    $diff = (int) $from->diff($to)->days;

    return $to < $from ? -$diff : $diff;
}

/**
 * Records that an account's daily run happened.
 *
 * @param string $column 'last_payment_run' or 'last_cancellation_run'
 */
function wallos_mark_notification_run(SQLite3 $db, $userId, $column, $today, $complete = true)
{
    if (!in_array($column, ['last_payment_run', 'last_cancellation_run'], true)) {
        return;
    }
    // The account may not have a settings row yet.
    $ensure = $db->prepare('INSERT INTO notification_settings (user_id, days) SELECT :userId, 1
        WHERE NOT EXISTS (SELECT 1 FROM notification_settings WHERE user_id = :userId)');
    $ensure->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
    $ensure->execute();
    if (!$complete) {
        // Some reminder was not delivered: leave the day open so the next
        // 15-minute run tries again, at most WALLOS_NOTIFICATION_MAX_ATTEMPTS
        // times a day; after that the day is closed (tomorrow's windows
        // still cover what remains due).
        $attemptsColumn = $column === 'last_payment_run' ? 'payment_run_attempts' : 'cancellation_run_attempts';
        $stmt = $db->prepare('SELECT ' . $attemptsColumn . ' FROM notification_settings WHERE user_id = :userId');
        $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
        $row = $stmt->execute()->fetchArray(SQLITE3_NUM);
        $attempts = 0;
        if ($row && is_string($row[0]) && strpos($row[0], $today . ':') === 0) {
            $attempts = (int) substr($row[0], strlen($today) + 1);
        }
        $attempts++;
        if ($attempts < WALLOS_NOTIFICATION_MAX_ATTEMPTS) {
            $stmt = $db->prepare('UPDATE notification_settings SET ' . $attemptsColumn . ' = :attempts WHERE user_id = :userId');
            $stmt->bindValue(':attempts', $today . ':' . $attempts, SQLITE3_TEXT);
            $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
            $stmt->execute();
            if ($db->changes() > 0) {
                return;
            }
        }
    }
    $stmt = $db->prepare('UPDATE notification_settings SET ' . $column . ' = :today WHERE user_id = :userId');
    $stmt->bindValue(':today', $today, SQLITE3_TEXT);
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
    $stmt->execute();
    if ($db->changes() === 0) {
        $stmt = $db->prepare('INSERT INTO notification_settings (user_id, days, ' . $column . ') VALUES (:userId, 1, :today)');
        $stmt->bindValue(':today', $today, SQLITE3_TEXT);
        $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
        $stmt->execute();
    }
}

/**
 * The payment reminders due today for one account.
 *
 * @param array  $settings its notification_settings row
 * @param string $today    local date, Y-m-d
 * @param array  $lookups  wallos_notification_lookups()
 * @return array [entries by payer id, pending: key => [subscription_id, due_date, offsets]]
 */
function wallos_collect_payment_reminders(SQLite3 $db, $userId, array $settings, $today, array $lookups)
{
    require_once __DIR__ . '/../intro_pricing.php';
    require_once __DIR__ . '/../cancellation_deadline.php';
    require_once __DIR__ . '/messages.php';

    $defaultDays = wallos_default_reminder_days($settings);
    $overdueOffsets = wallos_overdue_offsets($settings['repeat_every'] ?? 0, $settings['repeat_max'] ?? 0);
    $log = wallos_notification_log_load($db, $userId, 'payment');

    $stmt = $db->prepare('SELECT * FROM subscriptions WHERE user_id = :userId AND notify = 1 AND inactive = 0 ORDER BY payer_user_id ASC, id ASC');
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    $entries = [];
    $pending = [];
    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $due = wallos_payment_reminder_due($row, $today, $defaultDays, $overdueOffsets, $log, $row['last_notified_payment'] ?? null);
        if ($due === null) {
            continue;
        }
        [$dueDate, $daysUntil, $dueOffsets] = $due;

        $key = 'payment:' . (int) $row['id'];
        $entries[(int) $row['payer_user_id']][] = wallos_notification_entry($row, $lookups, $daysUntil, ['key' => $key]);
        $pending[$key] = ['subscription_id' => (int) $row['id'], 'due_date' => $dueDate, 'offsets' => $dueOffsets];
    }

    return [$entries, $pending];
}

/**
 * Whether a payment reminder of one subscription is due today, and for
 * which offsets.
 *
 * @param array       $row           a subscriptions row
 * @param int[]       $defaultDays   the reminder offsets when the row has none
 * @param int[]       $overdueOffsets
 * @param array       $log           [subscription_id][due_date] => offsets sent
 * @param string|null $lastNotified  the legacy marker (null: none)
 * @return array|null [due date, days until, offsets due]
 */
function wallos_payment_reminder_due(array $row, $today, array $defaultDays, array $overdueOffsets, array $log, $lastNotified)
{
    require_once __DIR__ . '/../intro_pricing.php';
    require_once __DIR__ . '/../cancellation_deadline.php';

    // A payment inside a free trial or promo gets the "trial ending"
    // reminder (sendcancellationnotifications.php) instead.
    if (wallos_intro_suppresses_payment_reminder($row)) {
        return null;
    }
    $dueDate = substr((string) $row['next_payment'], 0, 10);
    if (wallos_parse_date($dueDate) === null) {
        return null;
    }
    if (!empty($row['mute_payment_until_cancellation'])) {
        $deadline = wallos_cancel_deadline($row);
        if (wallos_payment_reminder_muted($row, $deadline['cancel_by'] ?? null)) {
            return null;
        }
    }

    $daysUntil = wallos_days_between($today, $dueDate);
    $offsets = wallos_subscription_reminder_days($row, $defaultDays);
    $logged = wallos_logged_offsets($log[(int) $row['id']][$dueDate] ?? [], $lastNotified, $dueDate, $daysUntil, $offsets);
    $dueOffsets = wallos_due_reminder_offsets($daysUntil, $offsets, $overdueOffsets, (int) $row['auto_renew'] === 1, $logged);

    return $dueOffsets === [] ? null : [$dueDate, $daysUntil, $dueOffsets];
}

/**
 * Logs what was delivered and stores the legacy marker.
 *
 * @param array $pending   from wallos_collect_payment_reminders()
 * @param array $delivered key => true
 */
function wallos_record_payment_reminders(SQLite3 $db, $userId, array $pending, array $delivered)
{
    // The log rows and the legacy marker are written together: a marker
    // without its rows would count later offsets as already sent.
    $inTransaction = @$db->exec('BEGIN IMMEDIATE') !== false;
    $ok = true;
    $logEntries = [];
    $mark = $db->prepare('UPDATE subscriptions SET last_notified_payment = :nextPayment WHERE id = :id AND user_id = :userId');
    foreach ($pending as $key => $reminder) {
        if (empty($delivered[$key])) {
            continue;
        }
        foreach ($reminder['offsets'] as $offset) {
            $logEntries[] = ['subscription_id' => $reminder['subscription_id'], 'due_date' => $reminder['due_date'], 'offset' => $offset];
        }
        $mark->bindValue(':nextPayment', $reminder['due_date'], SQLITE3_TEXT);
        $mark->bindValue(':id', $reminder['subscription_id'], SQLITE3_INTEGER);
        $mark->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
        $ok = $mark->execute() !== false;
        $mark->reset();
        if (!$ok) {
            break;
        }
    }
    $ok = $ok && wallos_notification_log_record($db, $userId, 'payment', $logEntries);
    if ($inTransaction) {
        $db->exec($ok ? 'COMMIT' : 'ROLLBACK');
    }

    return $ok;
}

/**
 * The cancellation, free-trial and promo reminders due today for one account.
 *
 * @return array [entries by payer id, markers: ['cancel' => id => marker, 'intro' => id => marker]]
 */
function wallos_collect_cancellation_reminders(SQLite3 $db, $userId, $today, array $lookups)
{
    require_once __DIR__ . '/../intro_pricing.php';
    require_once __DIR__ . '/../cancellation_deadline.php';
    require_once __DIR__ . '/messages.php';

    $entries = [];
    $markers = ['cancel' => [], 'intro' => []];

    // Candidates: active, recurring subscriptions with a cancellation notice.
    // wallos_cancellation_due() decides whether the reminder is due today.
    // One-time purchases have no recurring commitment to cancel.
    $stmt = $db->prepare(WALLOS_CANCELLATION_CANDIDATES_SQL);
    $stmt->bindValue(':user_id', (int) $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':inactive', 0, SQLITE3_INTEGER);
    $stmt->bindValue(':oneTimeCycle', 5, SQLITE3_INTEGER);
    $result = $stmt->execute();
    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $deadline = wallos_cancellation_due($row, $today);
        if ($deadline === null) {
            continue;
        }
        if ($deadline['renewal'] !== null) {
            $note = " (cancel by " . $deadline['cancel_by'] . " to avoid the renewal on " . $deadline['renewal'] . ")";
        } elseif ($deadline['stage'] === 'lead') {
            $note = " (cancel by " . $deadline['cancel_by'] . ")";
        } else {
            $note = "";
        }
        $markers['cancel'][(int) $row['id']] = $deadline['marker'];
        $entries[(int) $row['payer_user_id']][] = wallos_notification_entry($row, $lookups, wallos_days_between($today, $deadline['cancel_by']), [
            'cancel_by' => $deadline['cancel_by'],
            'deadline_note' => $note,
            'key' => wallos_notification_key('cancel', $row['id']),
        ]);
    }

    // Free trials and promos: a reminder before the regular price starts,
    // and a one-off notice once it has (see includes/intro_pricing.php).
    $stmt = $db->prepare('SELECT * FROM subscriptions WHERE user_id = :user_id AND inactive = 0 AND (intro_type IS NOT NULL OR intro_converted_on IS NOT NULL) ORDER BY payer_user_id ASC');
    $stmt->bindValue(':user_id', (int) $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $symbol = $lookups['currencies'][$row['currency_id']]['symbol'] ?? '';
        if (wallos_intro_ending_due($row, $today)) {
            $markers['intro'][(int) $row['id']] = $row['intro_end_date'];
            $note = ($row['intro_type'] === 'trial' ? " (free trial ends " : " (intro price ends ")
                . $row['intro_end_date'] . ", then " . $row['price'] . $symbol . ")";
            $cancelBy = $row['intro_end_date'];
            $price = wallos_intro_price($row) . $symbol;
        } elseif (wallos_intro_converted_due($row, $today)) {
            $markers['intro'][(int) $row['id']] = 'converted:' . $row['intro_converted_on'];
            $note = " (intro period ended " . $row['intro_converted_on'] . "; the regular price now applies)";
            $cancelBy = $row['intro_converted_on'];
            $price = $row['price'] . $symbol;
        } else {
            continue;
        }
        $entries[(int) $row['payer_user_id']][] = wallos_notification_entry($row, $lookups, wallos_days_between($today, $cancelBy), [
            'price' => $price,
            'cancel_by' => $cancelBy,
            'deadline_note' => $note,
            'key' => wallos_notification_key('intro', $row['id']),
        ]);
    }

    return [$entries, $markers];
}

/**
 * Stores the "sent" markers of the delivered cancellation and intro reminders.
 */
function wallos_record_cancellation_reminders(SQLite3 $db, $userId, array $markers, array $delivered)
{
    $mark = $db->prepare('UPDATE subscriptions SET last_cancel_notified_for = :marker WHERE id = :id AND user_id = :userId');
    foreach (wallos_delivered_markers($markers['cancel'], $delivered, 'cancel') as $subscriptionId => $marker) {
        $mark->bindValue(':marker', $marker, SQLITE3_TEXT);
        $mark->bindValue(':id', $subscriptionId, SQLITE3_INTEGER);
        $mark->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
        $mark->execute();
        $mark->reset();
    }
    $mark = $db->prepare('UPDATE subscriptions SET intro_notified_for = :marker WHERE id = :id AND user_id = :userId');
    foreach (wallos_delivered_markers($markers['intro'], $delivered, 'intro') as $subscriptionId => $marker) {
        $mark->bindValue(':marker', $marker, SQLITE3_TEXT);
        $mark->bindValue(':id', $subscriptionId, SQLITE3_INTEGER);
        $mark->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
        $mark->execute();
        $mark->reset();
    }
}

/**
 * The cancellation cron's candidate query.
 */
const WALLOS_CANCELLATION_CANDIDATES_SQL = "SELECT * FROM subscriptions WHERE user_id = :user_id AND inactive = :inactive AND cycle != :oneTimeCycle AND (cancel_notice_mode = 'before_renewal' OR (cancellation_date IS NOT NULL AND cancellation_date != '' AND COALESCE(cancel_notice_mode, 'fixed') = 'fixed')) ORDER BY payer_user_id ASC";

/**
 * The reminder fields of a subscription save request (issues #39, #40).
 *
 * @param array $input notify_days_before, reminder_days[] (chips; empty when
 *                     the default is used), cancellation_notify_days_before,
 *                     mute_payment_until_cancellation
 * @return array notify_days_before, reminder_days (string|null),
 *               cancellation_notify_days_before, mute_payment_until_cancellation
 */
function wallos_reminder_fields_from_input(array $input, ?array $existing = null)
{
    // Editing: a form that sent no payment schedule (reminders switched
    // off, so its fields were disabled) keeps the stored one, and one that
    // sent no lead time keeps that. An explicit -1 still picks the default.
    if ($existing !== null) {
        if (!array_key_exists('reminder_days', $input) && !array_key_exists('notify_days_before', $input)) {
            $input['reminder_days'] = $existing['reminder_days'] ?? null;
            $input['notify_days_before'] = $existing['notify_days_before'] ?? -1;
        }
        if (!array_key_exists('cancellation_notify_days_before', $input)) {
            $input['cancellation_notify_days_before'] = $existing['cancellation_notify_days_before'] ?? 0;
        }
    }
    $days = wallos_parse_reminder_days($input['reminder_days'] ?? null);
    $single = array_key_exists('notify_days_before', $input) ? $input['notify_days_before'] : -1;
    $single = $single === null ? null : (is_numeric($single) ? (int) $single : -1);
    if ($days !== []) {
        // The largest offset, for the API and the calendar feed.
        $single = max($days);
    } elseif ($single !== null && ($single < -1 || $single > WALLOS_REMINDER_MAX_OFFSET)) {
        $single = -1;
    }

    $lead = (int) ($input['cancellation_notify_days_before'] ?? 0);

    return [
        'notify_days_before' => $single,
        'reminder_days' => $days === [] ? null : wallos_format_reminder_days($days),
        'cancellation_notify_days_before' => max(0, min(365, $lead)),
        'mute_payment_until_cancellation' => !empty($input['mute_payment_until_cancellation']) ? 1 : 0,
    ];
}

/**
 * Stores the reminder fields on a subscription.
 */
function wallos_store_reminder_fields(SQLite3 $db, $subscriptionId, $userId, array $fields)
{
    $stmt = $db->prepare('UPDATE subscriptions SET notify_days_before = :single, reminder_days = :days,
        cancellation_notify_days_before = :lead, mute_payment_until_cancellation = :mute
        WHERE id = :id AND user_id = :userId');
    $stmt->bindValue(':single', $fields['notify_days_before'], $fields['notify_days_before'] === null ? SQLITE3_NULL : SQLITE3_INTEGER);
    $stmt->bindValue(':days', $fields['reminder_days'], $fields['reminder_days'] === null ? SQLITE3_NULL : SQLITE3_TEXT);
    $stmt->bindValue(':lead', $fields['cancellation_notify_days_before'], SQLITE3_INTEGER);
    $stmt->bindValue(':mute', $fields['mute_payment_until_cancellation'], SQLITE3_INTEGER);
    $stmt->bindValue(':id', (int) $subscriptionId, SQLITE3_INTEGER);
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);

    return $stmt->execute() !== false;
}

/**
 * Takes the lock of one reminder cron, so a run that overlaps another (a
 * slow run, or the admin's manual run) cannot send the same reminders
 * twice. Held until the process ends.
 *
 * @param string $name 'payment' or 'cancellation'
 * @return resource|false the lock handle, or false when another run holds it
 */
function wallos_acquire_notification_lock($name)
{
    $path = __DIR__ . '/../../db/.notifications-' . preg_replace('/[^a-z]/', '', $name) . '.lock';
    $handle = @fopen($path, 'c');
    if ($handle === false) {
        $handle = @fopen($path, 'r');
    } else {
        // The cron and the web server can run as different users.
        @chmod($path, 0666);
    }
    if ($handle === false) {
        return false;
    }
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        return false;
    }

    return $handle;
}
