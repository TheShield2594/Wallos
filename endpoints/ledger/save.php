<?php
/*
  Edit one payment: POST {"id": 5, "amount": 41.37, "paid_date": "2026-10-03", "note": ""}
  Saving an amount confirms the payment.
*/
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/payment_ledger.php';

header('Content-Type: application/json');

$data = json_decode(file_get_contents("php://input"), true);
if (!is_array($data) || !wallos_ledger_save($db, $userId, (int) ($data['id'] ?? 0), $data['amount'] ?? null, $data['paid_date'] ?? null, $data['note'] ?? '')) {
    die(json_encode(['success' => false, 'message' => translate('error', $i18n)]));
}

echo json_encode(['success' => true, 'message' => translate('success', $i18n)]);
