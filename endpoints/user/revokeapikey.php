<?php
/*
  Revoke one of the signed-in account's API keys (issue #43). POST with CSRF.

  Body: {"id": 12}
*/

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/api_keys.php';

$data = json_decode(file_get_contents('php://input'), true);
$keyId = (int) ($data['id'] ?? 0);

$revoked = $keyId > 0 && wallos_api_revoke_key($db, $keyId, $userId);
$db->close();

echo json_encode([
    "success" => $revoked,
    "message" => translate($revoked ? 'api_key_revoked' : 'error', $i18n),
]);
