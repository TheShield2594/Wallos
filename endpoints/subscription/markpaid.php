<?php
/*
  Mark the current cycle of a recurring subscription as paid.

  POST {"id": 12, "amount": 41.37}   amount is optional (defaults to the price)

  Manual renewals move on one cycle; automatic renewals confirm the most
  recent unconfirmed cycle, or the upcoming one when paid early. See
  wallos_mark_paid() in includes/payment_ledger.php.
*/
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/payment_ledger.php';

header('Content-Type: application/json');

$data = json_decode(file_get_contents("php://input"), true);
$subscriptionId = is_array($data) ? (int) ($data['id'] ?? 0) : 0;
$amount = is_array($data) && isset($data['amount']) && $data['amount'] !== '' ? $data['amount'] : null;

$subscription = wallos_ledger_load_subscription($db, $userId, $subscriptionId);
if ($subscription === false) {
    die(json_encode(["success" => false, "message" => translate("error", $i18n)]));
}

$outcome = wallos_mark_paid($db, $subscription, new DateTime('today'), $amount);
if (!$outcome['success']) {
    $message = ($outcome['error'] ?? '') === 'already_paid' ? translate('payment_already_recorded', $i18n) : translate('error', $i18n);
    die(json_encode(["success" => false, "message" => $message]));
}

echo json_encode([
    "success" => true,
    "message" => translate($outcome['ended'] ? 'plan_completed' : 'marked_as_paid', $i18n),
    "id" => $subscriptionId,
    "due_date" => $outcome['due_date'],
    "next_payment" => $outcome['next_payment'],
    "ended" => $outcome['ended'],
]);
