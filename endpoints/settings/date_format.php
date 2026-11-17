<?php
/*
  Settings > Display > Date format (see includes/date_format.php):
  POST {"value": "auto" | "dmy" | "mdy" | "iso" | "d_mon_y" | "mon_d_y"}
*/
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/date_format.php';

$data = json_decode(file_get_contents('php://input'), true);
$format = $data['value'] ?? null;

if (!is_string($format) || !array_key_exists($format, WALLOS_DATE_FORMATS)) {
    die(json_encode([
        'success' => false,
        'message' => translate('error', $i18n),
    ]));
}

$stmt = $db->prepare('UPDATE settings SET date_format = :format WHERE user_id = :userId');
$stmt->bindValue(':format', $format, SQLITE3_TEXT);
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
