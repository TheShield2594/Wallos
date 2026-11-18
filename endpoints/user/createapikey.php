<?php
/*
  Create an API key for the signed-in account (issue #43). POST with CSRF.

  Body: {"name": "Home Assistant", "scopes": ["read"],
         "password": "...", "totpCode": "123456"}

  password / totpCode are only needed for the admin:read scope, which only
  admins can ask for (see wallos_api_reauthenticate() for what counts).

  The full key is in the response and is never shown again: only its hash is
  stored.
*/

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/api_keys.php';

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    $data = [];
}

$name = trim((string) ($data['name'] ?? ''));
$scopes = wallos_api_normalize_scopes(is_array($data['scopes'] ?? null) ? $data['scopes'] : (string) ($data['scopes'] ?? ''));

if ($name === '' || mb_strlen($name) > 100) {
    die(json_encode(["success" => false, "message" => translate('api_key_name_required', $i18n)]));
}
if ($scopes === []) {
    die(json_encode(["success" => false, "message" => translate('api_key_scope_required', $i18n)]));
}
if (wallos_api_active_key_count($db, $userId) >= WALLOS_API_MAX_KEYS_PER_USER) {
    die(json_encode(["success" => false, "message" => translate('api_key_limit_reached', $i18n)]));
}

if (in_array('admin:read', $scopes, true)) {
    if (!wallos_is_admin($db, $userId)) {
        die(json_encode(["success" => false, "message" => translate('error', $i18n)]));
    }
    if (!wallos_api_reauthenticate($db, $userId, (string) ($data['password'] ?? ''), (string) ($data['totpCode'] ?? ''), $_SESSION)) {
        die(json_encode(["success" => false, "message" => translate('api_key_reauth_failed', $i18n)]));
    }
}

$created = wallos_api_create_key($db, $userId, $name, $scopes);
$db->close();

if (!$created['success']) {
    die(json_encode(["success" => false, "message" => translate('error', $i18n)]));
}

echo json_encode([
    "success" => true,
    "message" => translate('api_key_created', $i18n),
    "apiKey" => $created['key'],
    "id" => $created['id'],
    "prefix" => $created['prefix'],
    "scopes" => $created['scopes'],
]);
