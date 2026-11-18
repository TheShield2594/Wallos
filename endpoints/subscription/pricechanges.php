<?php
/*
  Price and plan history of one subscription: GET ?id=12
  Returns `changes` (newest first) and `history`, the prices it has had
  (oldest first).
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

$subscriptionId = (int) ($_GET['id'] ?? 0);
$changes = wallos_price_changes_list($db, $userId, $subscriptionId, 24);

$stmt = $db->prepare('SELECT price, currency_id, cycle, frequency, start_date FROM subscriptions WHERE id = :id AND user_id = :userId');
$stmt->bindValue(':id', $subscriptionId, SQLITE3_INTEGER);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$subscriptionResult = $stmt->execute();
$subscription = $subscriptionResult ? $subscriptionResult->fetchArray(SQLITE3_ASSOC) : false;
// The prices the subscription has had, oldest first, for the chart and the
// "since" summary. Only the last 24 changes are listed, so the starting
// price is the oldest one listed.
$history = $subscription ? wallos_price_history_points($subscription, $changes) : [];

echo json_encode([
    'success' => true,
    'history' => $history,
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
