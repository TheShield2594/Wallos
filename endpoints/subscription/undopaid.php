<?php
/*
  Take back a "Mark paid".

  POST {"id": 12, "due_date": "2026-10-03"}
*/
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/payment_ledger.php';

header('Content-Type: application/json');

$data = json_decode(file_get_contents("php://input"), true);
$subscriptionId = is_array($data) ? (int) ($data['id'] ?? 0) : 0;
$dueDate = is_array($data) ? wallos_parse_date($data['due_date'] ?? null) : null;

$subscription = wallos_ledger_load_subscription($db, $userId, $subscriptionId);
if ($subscription === false || $dueDate === null) {
    die(json_encode(["success" => false, "message" => translate("error", $i18n)]));
}

$outcome = wallos_undo_paid($db, $subscription, $dueDate->format('Y-m-d'));
if (!$outcome['success']) {
    $message = $outcome['error'] === 'not_latest' ? translate('undo_only_latest_payment', $i18n) : translate('error', $i18n);
    die(json_encode(["success" => false, "message" => $message]));
}

echo json_encode(["success" => true, "message" => translate('payment_undone', $i18n), "id" => $subscriptionId]);
