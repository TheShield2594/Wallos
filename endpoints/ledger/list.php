<?php
/*
  Payment history of one subscription: GET ?id=12
*/
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/payment_ledger.php';
require_once '../../includes/households.php';

header('Content-Type: application/json');

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    http_response_code(401);
    die(json_encode(['success' => false, 'message' => translate('session_expired', $i18n)]));
}

// Readable by the owner and by the household it is shared with.
$subscription = wallos_load_accessible_subscription($db, $userId, (int) ($_GET['id'] ?? 0), 'read');
if ($subscription === false) {
    die(json_encode(['success' => false, 'message' => translate('error', $i18n)]));
}

echo json_encode([
    'success' => true,
    'payments' => wallos_ledger_list($db, (int) $subscription['user_id'], $subscription['id'], 12),
]);
