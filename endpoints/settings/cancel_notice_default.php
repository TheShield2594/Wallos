<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/cancellation_deadline.php';

// The notice period prefilled when a subscription is set to remind before
// each renewal. A null value clears it.
$data = json_decode(file_get_contents("php://input"), true);
$value = $data['value']['value'] ?? null;
$unit = $data['value']['unit'] ?? 'months';

if (($value !== null && !wallos_cancel_notice_valid($value, $unit)) || !in_array($unit, WALLOS_CANCEL_UNITS, true)) {
    die(json_encode([
        "success" => false,
        "message" => translate("error", $i18n)
    ]));
}

$stmt = $db->prepare('UPDATE settings SET default_cancel_notice_value = :value, default_cancel_notice_unit = :unit WHERE user_id = :userId');
$stmt->bindValue(':value', $value === null ? null : (int) $value, $value === null ? SQLITE3_NULL : SQLITE3_INTEGER);
$stmt->bindValue(':unit', $unit, SQLITE3_TEXT);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);

if ($stmt->execute()) {
    die(json_encode([
        "success" => true,
        "message" => translate("success", $i18n)
    ]));
}

die(json_encode([
    "success" => false,
    "message" => translate("error", $i18n)
]));
