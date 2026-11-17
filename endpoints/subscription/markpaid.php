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
require_once '../../includes/household_payments.php';

header('Content-Type: application/json');

$data = json_decode(file_get_contents("php://input"), true);
$subscriptionId = is_array($data) ? (int) ($data['id'] ?? 0) : 0;
$amount = is_array($data) && isset($data['amount']) && $data['amount'] !== '' ? $data['amount'] : null;

// The owner, or a household editor of a shared subscription; an amount a
// member entered is converted to the owner's currency, or refused without a
// conversion path (see includes/household_payments.php).
$outcome = wallos_member_mark_paid($db, $userId, $subscriptionId, $amount, new DateTime('today'));
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
