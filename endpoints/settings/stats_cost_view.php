<?php
/*
  Settings > Display > Statistics show (see includes/cost_sharing.php):
  POST {"value": "gross" | "net"}
*/
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/cost_sharing.php';

$data = json_decode(file_get_contents('php://input'), true);
$mode = $data['value'] ?? null;

if (!is_string($mode) || !in_array($mode, WALLOS_STATS_COST_MODES, true)) {
    die(json_encode([
        'success' => false,
        'message' => translate('error', $i18n),
    ]));
}

$stmt = $db->prepare('UPDATE settings SET stats_net_cost = :net WHERE user_id = :userId');
$stmt->bindValue(':net', $mode === 'net' ? 1 : 0, SQLITE3_INTEGER);
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
