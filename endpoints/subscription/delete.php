<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/logo_cleanup.php';

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

$subscriptionId = $data["id"];

$logoStmt = $db->prepare("SELECT logo, logo_variant FROM subscriptions WHERE id = :subscriptionId AND user_id = :userId");
$logoStmt->bindParam(':subscriptionId', $subscriptionId, SQLITE3_INTEGER);
$logoStmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
$logoResult = $logoStmt->execute();
$logoRow = $logoResult ? $logoResult->fetchArray(SQLITE3_ASSOC) : false;

// The row, its payment and price history, and the references to it go
// together: if any part fails, nothing is deleted.
$deleteStatements = [
    // Subscriptions billed through this one become standalone again (issue #24).
    "UPDATE subscriptions SET parent_subscription_id = NULL, allocated_amount = NULL WHERE parent_subscription_id = :subscriptionId AND user_id = :userId",
    "DELETE FROM subscriptions WHERE id = :subscriptionId AND user_id = :userId",
    "DELETE FROM subscription_payments WHERE subscription_id = :subscriptionId AND user_id = :userId",
    "DELETE FROM subscription_price_changes WHERE subscription_id = :subscriptionId AND user_id = :userId",
    "DELETE FROM subscription_shares WHERE subscription_id = :subscriptionId AND user_id = :userId",
    "DELETE FROM notification_log WHERE subscription_id = :subscriptionId AND user_id = :userId",
    "DELETE FROM notification_recipient_log WHERE subscription_id = :subscriptionId AND owner_user_id = :userId",
    "UPDATE subscriptions SET replacement_subscription_id = NULL WHERE replacement_subscription_id = :subscriptionId AND user_id = :userId",
];
$deleted = @$db->exec('BEGIN IMMEDIATE') !== false;
foreach ($deleteStatements as $query) {
    if (!$deleted) {
        break;
    }
    $stmt = $db->prepare($query);
    $deleted = $stmt !== false;
    if ($deleted) {
        $stmt->bindValue(':subscriptionId', (int) $subscriptionId, SQLITE3_INTEGER);
        $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $deleted = $stmt->execute() !== false;
    }
}
if ($deleted && $db->exec('COMMIT') === false) {
    $deleted = false;
}
if (!$deleted) {
    @$db->exec('ROLLBACK');
}

if ($deleted) {
    if ($logoRow !== false) {
        deleteLogoFileIfUnused($db, $logoRow['logo'], '../../images/uploads/logos/');
        deleteLogoFileIfUnused($db, $logoRow['logo_variant'], '../../images/uploads/logos/');
    }

    echo json_encode([
        "success" => true,
        "message" => translate('subscription_deleted', $i18n)
    ]);
} else {
    echo json_encode([
        "success" => false,
        "message" => translate('error_deleting_subscription', $i18n)
    ]);
}
$db->close();
