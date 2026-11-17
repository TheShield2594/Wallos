<?php
/*
  Payment history of one subscription: GET ?id=12
*/
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/payment_ledger.php';

header('Content-Type: application/json');

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    http_response_code(401);
    die(json_encode(['success' => false, 'message' => translate('session_expired', $i18n)]));
}

$subscription = wallos_ledger_load_subscription($db, $userId, (int) ($_GET['id'] ?? 0));
if ($subscription === false) {
    die(json_encode(['success' => false, 'message' => translate('error', $i18n)]));
}

echo json_encode([
    'success' => true,
    'payments' => wallos_ledger_list($db, $userId, $subscription['id'], 12),
]);
