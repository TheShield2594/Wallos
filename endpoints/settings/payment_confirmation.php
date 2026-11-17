<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

$requirePaymentConfirmation = $data['value'];

// Validate input
if (!isset($requirePaymentConfirmation) || !is_bool($requirePaymentConfirmation)) {
    die(json_encode([
        "success" => false,
        "message" => translate("error", $i18n)
    ]));
}

$stmt = $db->prepare('UPDATE settings SET require_payment_confirmation = :require_payment_confirmation WHERE user_id = :userId');
$stmt->bindParam(':require_payment_confirmation', $requirePaymentConfirmation, SQLITE3_INTEGER);
$stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);

if ($stmt->execute()) {
    die(json_encode([
        "success" => true,
        "message" => translate("success", $i18n)
    ]));
} else {
    die(json_encode([
        "success" => false,
        "message" => translate("error", $i18n)
    ]));
}