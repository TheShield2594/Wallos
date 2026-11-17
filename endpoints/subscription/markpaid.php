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
require_once '../../includes/households.php';

header('Content-Type: application/json');

$data = json_decode(file_get_contents("php://input"), true);
$subscriptionId = is_array($data) ? (int) ($data['id'] ?? 0) : 0;
$amount = is_array($data) && isset($data['amount']) && $data['amount'] !== '' ? $data['amount'] : null;

// The owner, or a household editor of a shared subscription.
$subscription = wallos_load_accessible_subscription($db, $userId, $subscriptionId, 'edit');
if ($subscription === false) {
    die(json_encode(["success" => false, "message" => translate("error", $i18n)]));
}

// An amount a household member entered was in the currency they were shown;
// the ledger keeps the owner's currency. Without a conversion path (the
// currencies changed while the dialog was open) the request is refused.
if ($subscription['access'] !== 'owner') {
    $factor = wallos_household_display_factor($db, $userId, $subscription['currency_id']);
    if ($factor === null) {
        die(json_encode(["success" => false, "message" => translate("error", $i18n)]));
    }
    if ($amount !== null && is_numeric($amount)) {
        $amount = (float) $amount / $factor;
    }
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
