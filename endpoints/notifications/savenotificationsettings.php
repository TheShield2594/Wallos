<?php
/*
  Saves the account's reminder schedule (Settings -> Notifications).

  POST JSON:
    reminder_days                   [7, 3, 0]: default reminder offsets. The
                                    legacy single `days` is still accepted.
    send_time                       "HH:MM", 15-minute steps
    timezone                        IANA name, or "" for the server's
    repeat_every, repeat_max        overdue repeats for subscriptions that do
                                    not renew automatically (0 = off)
    period_summary_at_period_start  0/1
*/

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/notifications/schedule.php';

$data = json_decode(file_get_contents("php://input"), true);
if (!is_array($data)) {
    die(json_encode(["success" => false, "message" => translate('fill_mandatory_fields', $i18n)]));
}

$reminderDays = wallos_parse_reminder_days($data['reminder_days'] ?? ($data['days'] ?? null));
if ($reminderDays === []) {
    die(json_encode(["success" => false, "message" => translate('fill_mandatory_fields', $i18n)]));
}

$sendTime = wallos_normalize_send_time((string) ($data['send_time'] ?? '09:00'));
if ($sendTime === null) {
    die(json_encode(["success" => false, "message" => translate('invalid_send_time', $i18n)]));
}

$timezone = trim((string) ($data['timezone'] ?? ''));
if ($timezone !== '' && !wallos_valid_timezone($timezone)) {
    die(json_encode(["success" => false, "message" => translate('invalid_timezone', $i18n)]));
}

$repeatEvery = max(0, min(90, (int) ($data['repeat_every'] ?? 0)));
$repeatMax = max(0, min(52, (int) ($data['repeat_max'] ?? 0)));
if ($repeatEvery === 0 || $repeatMax === 0) {
    $repeatEvery = 0;
    $repeatMax = 0;
}
$periodSummary = !empty($data['period_summary_at_period_start']) ? 1 : 0;

$stmt = $db->prepare('SELECT COUNT(*) FROM notification_settings WHERE user_id = :userId');
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$exists = (int) $stmt->execute()->fetchArray(SQLITE3_NUM)[0] > 0;

$columns = 'days = :days, reminder_days = :reminderDays, send_time = :sendTime, timezone = :timezone,
    repeat_every = :repeatEvery, repeat_max = :repeatMax, period_summary_at_period_start = :periodSummary';
$query = $exists
    ? 'UPDATE notification_settings SET ' . $columns . ' WHERE user_id = :userId'
    : 'INSERT INTO notification_settings (user_id, days, reminder_days, send_time, timezone, repeat_every, repeat_max, period_summary_at_period_start)
        VALUES (:userId, :days, :reminderDays, :sendTime, :timezone, :repeatEvery, :repeatMax, :periodSummary)';

$stmt = $db->prepare($query);
// `days` keeps the largest offset, for the API and the iCal feed.
$stmt->bindValue(':days', max($reminderDays), SQLITE3_INTEGER);
$stmt->bindValue(':reminderDays', wallos_format_reminder_days($reminderDays), SQLITE3_TEXT);
$stmt->bindValue(':sendTime', $sendTime, SQLITE3_TEXT);
$stmt->bindValue(':timezone', $timezone === '' ? null : $timezone, $timezone === '' ? SQLITE3_NULL : SQLITE3_TEXT);
$stmt->bindValue(':repeatEvery', $repeatEvery, SQLITE3_INTEGER);
$stmt->bindValue(':repeatMax', $repeatMax, SQLITE3_INTEGER);
$stmt->bindValue(':periodSummary', $periodSummary, SQLITE3_INTEGER);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);

echo json_encode($stmt && $stmt->execute()
    ? ["success" => true, "message" => translate('notifications_settings_saved', $i18n)]
    : ["success" => false, "message" => translate('error_saving_notifications', $i18n)]);
