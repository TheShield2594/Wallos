<?php
/*
  Updates just the reminder settings of one subscription, so the details
  panel can toggle them in place without going through the full edit form.

  POST JSON: { id: int, notify: bool, notify_days_before: int }
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
$notifyDaysBefore = isset($data['notify_days_before']) ? (int) $data['notify_days_before'] : -1;
if ($notifyDaysBefore < -1 || $notifyDaysBefore > 180) {
    die(json_encode(["success" => false, "message" => translate('error', $i18n)]));
}

// One-time purchases never notify, matching the add/edit form.
$stmt = $db->prepare("UPDATE subscriptions SET notify = :notify, notify_days_before = :days
    WHERE id = :id AND user_id = :userId AND cycle != 5");
$stmt->bindValue(':notify', $notify, SQLITE3_INTEGER);
$stmt->bindValue(':days', $notifyDaysBefore, SQLITE3_INTEGER);
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
