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
$shownCurrencyId = is_array($data) && isset($data['currency_id']) && $data['currency_id'] !== '' ? (int) $data['currency_id'] : null;

// The owner, or a household editor of a shared subscription; an amount a
// member entered is converted to the owner's currency, or refused without a
// conversion path (see includes/household_payments.php).
$outcome = wallos_member_mark_paid($db, $userId, $subscriptionId, $amount, new DateTime('today'), $shownCurrencyId);
if (!$outcome['success']) {
    $messages = [
        'already_paid' => 'payment_already_recorded',
        // A shared subscription whose currencies changed since the dialog opened.
        'no_conversion' => 'household_currency_changed',
        'currency_changed' => 'household_currency_changed',
    ];
    $message = translate($messages[$outcome['error'] ?? ''] ?? 'error', $i18n);
    die(json_encode(["success" => false, "error" => $outcome['error'] ?? 'error', "message" => $message]));
}

echo json_encode([
    "success" => true,
    "message" => translate($outcome['ended'] ? 'plan_completed' : 'marked_as_paid', $i18n),
    "id" => $subscriptionId,
    "due_date" => $outcome['due_date'],
    "next_payment" => $outcome['next_payment'],
    "ended" => $outcome['ended'],
]);
