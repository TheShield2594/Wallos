<?php
/*
  Payment history of one subscription: GET ?id=12
*/
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/payment_ledger.php';
require_once '../../includes/household_payments.php';

header('Content-Type: application/json');

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    http_response_code(401);
    die(json_encode(['success' => false, 'message' => translate('session_expired', $i18n)]));
}

// Readable by the owner and by the household it is shared with; amounts are
// converted for a member, or refused without a conversion path (see
// includes/household_payments.php).
$history = wallos_member_ledger_list($db, $userId, (int) ($_GET['id'] ?? 0), 12);
if (!$history['success']) {
    die(json_encode(['success' => false, 'message' => translate('error', $i18n)]));
}
$payments = $history['payments'];

echo json_encode([
    'success' => true,
    'payments' => $payments,
]);
