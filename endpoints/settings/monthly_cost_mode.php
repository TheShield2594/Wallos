<?php
/*
  "Monthly cost counts" (see includes/monthly_cost.php):
  POST {"value": "amortized" | "short_cycles" | "cashflow"}
*/
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/monthly_cost.php';

$data = json_decode(file_get_contents('php://input'), true);
$mode = $data['value'] ?? null;

if (!is_string($mode) || !in_array($mode, WALLOS_MONTHLY_COST_MODES, true)) {
    die(json_encode([
        'success' => false,
        'message' => translate('error', $i18n),
    ]));
}

$stmt = $db->prepare('UPDATE settings SET monthly_cost_mode = :mode WHERE user_id = :userId');
$stmt->bindValue(':mode', $mode, SQLITE3_TEXT);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);

if ($stmt->execute()) {
    die(json_encode([
        'success' => true,
        'message' => translate('success', $i18n),
    ]));
}

die(json_encode([
    'success' => false,
    'message' => translate('error', $i18n),
]));
