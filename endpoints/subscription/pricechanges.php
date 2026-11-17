<?php
/*
  Price and plan history of one subscription: GET ?id=12
  (see includes/price_changes.php). The owner's history only: amounts are in
  the owner's currencies.
*/
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/price_changes.php';

header('Content-Type: application/json');

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    http_response_code(401);
    die(json_encode(['success' => false, 'message' => translate('session_expired', $i18n)]));
}

$changes = wallos_price_changes_list($db, $userId, (int) ($_GET['id'] ?? 0), 24);

echo json_encode([
    'success' => true,
    'changes' => array_map(function ($change) {
        return [
            'id' => (int) $change['id'],
            'changed_at' => $change['changed_at'],
            'old_price' => $change['old_price'] === null ? null : (float) $change['old_price'],
            'new_price' => $change['new_price'] === null ? null : (float) $change['new_price'],
            'old_currency_id' => (int) $change['old_currency_id'],
            'currency_id' => (int) $change['currency_id'],
            'old_cycle' => (int) $change['old_cycle'],
            'new_cycle' => (int) $change['new_cycle'],
            'old_frequency' => (int) $change['old_frequency'],
            'new_frequency' => (int) $change['new_frequency'],
            'old_monthly' => $change['old_monthly'] === null ? null : (float) $change['old_monthly'],
            'new_monthly' => $change['new_monthly'] === null ? null : (float) $change['new_monthly'],
            'old_tier' => $change['old_tier'],
            'new_tier' => $change['new_tier'],
            'reason' => $change['reason'],
        ];
    }, $changes),
]);
