<?php
/*
  Delete one payment from the history: POST {"id": 5}
  The subscription's schedule is not changed; use undopaid.php for that.
*/
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/payment_ledger.php';

header('Content-Type: application/json');

$data = json_decode(file_get_contents("php://input"), true);
if (!is_array($data) || !wallos_ledger_delete($db, $userId, (int) ($data['id'] ?? 0))) {
    die(json_encode(['success' => false, 'message' => translate('error', $i18n)]));
}

echo json_encode(['success' => true, 'message' => translate('success', $i18n)]);
