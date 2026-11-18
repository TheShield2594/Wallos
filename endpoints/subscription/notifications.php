<?php
/*
  Updates just the reminder settings of one subscription, so the details
  panel can toggle them in place without going through the full edit form.

  POST JSON: { id: int, notify: bool, notify_days_before?: int }
  notify_days_before: -1 = default from settings, 0 = on the due date,
  1..180 = days before.
*/
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data) || !isset($data['id'])) {
    die(json_encode(["success" => false, "message" => translate('fields_missing', $i18n)]));
}

$subscriptionId = (int) $data['id'];
$notify = !empty($data['notify']) ? 1 : 0;
// The switch sends only `notify`; the lead select also sends
// notify_days_before, which replaces a list of reminders with that one.
$hasSchedule = array_key_exists('notify_days_before', $data);
$notifyDaysBefore = $hasSchedule ? (int) $data['notify_days_before'] : null;
if ($hasSchedule && ($notifyDaysBefore < -1 || $notifyDaysBefore > 365)) {
    die(json_encode(["success" => false, "message" => translate('error', $i18n)]));
}

// One-time purchases never notify, matching the add/edit form.
$stmt = $db->prepare($hasSchedule
    ? "UPDATE subscriptions SET notify = :notify, notify_days_before = :days, reminder_days = NULL
        WHERE id = :id AND user_id = :userId AND cycle != 5"
    : "UPDATE subscriptions SET notify = :notify WHERE id = :id AND user_id = :userId AND cycle != 5");
$stmt->bindValue(':notify', $notify, SQLITE3_INTEGER);
if ($hasSchedule) {
    $stmt->bindValue(':days', $notifyDaysBefore, SQLITE3_INTEGER);
}
$stmt->bindValue(':id', $subscriptionId, SQLITE3_INTEGER);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$stmt->execute();

if ($db->changes() === 0) {
    die(json_encode(["success" => false, "message" => translate('error', $i18n)]));
}

echo json_encode([
    "success" => true,
    "message" => translate('subscription_updated_successfuly', $i18n),
]);
