<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

$subscriptionId = $data["id"];
$query = "SELECT * FROM subscriptions WHERE id = :id AND user_id = :user_id";
$stmt = $db->prepare($query);
$stmt->bindValue(':id', $subscriptionId, SQLITE3_INTEGER);
$stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
$subscriptionToClone = $result->fetchArray(SQLITE3_ASSOC);
if ($subscriptionToClone === false) {
    die(json_encode([
        "success" => false,
        "message" => translate("error", $i18n)
    ]));
}

$query = "INSERT INTO subscriptions (name, logo, price, currency_id, next_payment, auto_renew, start_date, cycle, frequency, notes, payment_method_id, payer_user_id, category_id, notify, url, inactive, notify_days_before, user_id, cancellation_date, replacement_subscription_id, billing_anchor_day, renew_from_action, variable_amount, end_date, total_payments, payments_made, ended_reason, plan_mode) VALUES (:name, :logo, :price, :currency_id, :next_payment, :auto_renew, :start_date, :cycle, :frequency, :notes, :payment_method_id, :payer_user_id, :category_id, :notify, :url, :inactive, :notify_days_before, :user_id, :cancellation_date, :replacement_subscription_id, :billing_anchor_day, :renew_from_action, :variable_amount, :end_date, :total_payments, :payments_made, :ended_reason, :plan_mode)";
$cloneStmt = $db->prepare($query);
$cloneStmt->bindValue(':name', $subscriptionToClone['name'], SQLITE3_TEXT);
$cloneStmt->bindValue(':logo', $subscriptionToClone['logo'], SQLITE3_TEXT);
$cloneStmt->bindValue(':price', $subscriptionToClone['price'], SQLITE3_TEXT);
$cloneStmt->bindValue(':currency_id', $subscriptionToClone['currency_id'], SQLITE3_INTEGER);
$cloneStmt->bindValue(':next_payment', $subscriptionToClone['next_payment'], SQLITE3_TEXT);
$cloneStmt->bindValue(':auto_renew', $subscriptionToClone['auto_renew'], SQLITE3_INTEGER);
$cloneStmt->bindValue(':start_date', $subscriptionToClone['start_date'], SQLITE3_TEXT);
$cloneStmt->bindValue(':cycle', $subscriptionToClone['cycle'], SQLITE3_TEXT);
$cloneStmt->bindValue(':frequency', $subscriptionToClone['frequency'], SQLITE3_INTEGER);
$cloneStmt->bindValue(':notes', $subscriptionToClone['notes'], SQLITE3_TEXT);
$cloneStmt->bindValue(':payment_method_id', $subscriptionToClone['payment_method_id'], SQLITE3_INTEGER);
$cloneStmt->bindValue(':payer_user_id', $subscriptionToClone['payer_user_id'], SQLITE3_INTEGER);
$cloneStmt->bindValue(':category_id', $subscriptionToClone['category_id'], SQLITE3_INTEGER);
$cloneStmt->bindValue(':notify', $subscriptionToClone['notify'], SQLITE3_INTEGER);
$cloneStmt->bindValue(':url', $subscriptionToClone['url'], SQLITE3_TEXT);
$cloneStmt->bindValue(':inactive', $subscriptionToClone['inactive'], SQLITE3_INTEGER);
$cloneStmt->bindValue(':notify_days_before', $subscriptionToClone['notify_days_before'], SQLITE3_INTEGER);
$cloneStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
$cloneStmt->bindValue(':cancellation_date', $subscriptionToClone['cancellation_date'], SQLITE3_TEXT);
$cloneStmt->bindValue(':replacement_subscription_id', $subscriptionToClone['replacement_subscription_id'], SQLITE3_INTEGER);
foreach (['billing_anchor_day', 'renew_from_action', 'variable_amount', 'total_payments', 'payments_made'] as $scheduleColumn) {
    $value = $subscriptionToClone[$scheduleColumn] ?? null;
    $cloneStmt->bindValue(':' . $scheduleColumn, $value, $value === null ? SQLITE3_NULL : SQLITE3_INTEGER);
}
foreach (['end_date', 'ended_reason', 'plan_mode'] as $scheduleColumn) {
    $value = $subscriptionToClone[$scheduleColumn] ?? null;
    $cloneStmt->bindValue(':' . $scheduleColumn, $value, $value === null ? SQLITE3_NULL : SQLITE3_TEXT);
}

// The new row and the settings copied onto it are one unit: a failed copy
// leaves no half-made clone behind.
$cloned = @$db->exec('BEGIN IMMEDIATE') !== false;
if ($cloned) {
    $cloned = $cloneStmt->execute() !== false;
}
if ($cloned) {
    $newId = $db->lastInsertRowID();

    // Settings carried over as they are: the cancellation notice and an
    // intro period (the reminder state starts fresh).
    $carriedColumns = ['cancel_notice_mode', 'cancel_notice_value', 'cancel_notice_unit', 'min_term_end',
        'intro_type', 'intro_price', 'intro_end_date', 'intro_notify_days', 'tier_name'];
    $columnList = implode(', ', $carriedColumns);
    $carryStmt = $db->prepare("UPDATE subscriptions SET ($columnList) = (SELECT $columnList FROM subscriptions WHERE id = :sourceId AND user_id = :userId)
        WHERE id = :newId AND user_id = :userId");
    $carryStmt->bindValue(':sourceId', $subscriptionId, SQLITE3_INTEGER);
    $carryStmt->bindValue(':newId', $newId, SQLITE3_INTEGER);
    $carryStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $cloned = $carryStmt->execute() !== false && $db->exec('COMMIT') !== false;
}
if (!$cloned) {
    @$db->exec('ROLLBACK');
}

if ($cloned) {
    $response = [
        "success" => true,
        "message" => translate('success', $i18n),
        "id" => $newId
    ];
    echo json_encode($response);
} else {
    die(json_encode([
        "success" => false,
        "message" => translate("error", $i18n)
    ]));
}

$db->close();
?>
