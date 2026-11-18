<?php
/*
This API Endpoint accepts POST requests only.
It receives the following parameters:
- api_key: the API key of the user (must belong to an admin).
  Send it as "Authorization: Bearer <key>" (or an X-API-Key header); the api_key
  parameter still works but is deprecated. Required scope: write. The key's owner must be an admin.
- oidc_enabled: (optional) '1' to enable OIDC logins, '0' to disable.
- name: (optional) provider name.
- client_id: (optional) OAuth client ID.
- client_secret: (optional) OAuth client secret.
- authorization_url: (optional) authorization endpoint.
- token_url: (optional) token endpoint.
- user_info_url: (optional) userinfo endpoint.
- redirect_url: (optional) callback/redirect URL.
- logout_url: (optional) logout/end-session URL.
- user_identifier_field: (optional) field identifier (e.g. sub).
- scopes: (optional) scope list.
- auth_style: (optional) authentication style (auto/header/params).
- auto_create_user: (optional) '1' to auto-register new OIDC users, '0' otherwise.
- password_login_disabled: (optional) '1' to disable password logins, '0' otherwise.
- require_email_verified: (optional) '1' to reject unverified emails, '0' otherwise.
- roles_claim: (optional) claim holding the user's groups or roles (e.g. groups, or realm_access.roles).
- admin_values: (optional) comma-separated values of that claim that make an account an admin.
  Password login can only be disabled while at least one admin account is linked to OIDC.

It returns a JSON object with the following properties:
- success: whether the request was successful (boolean).
- title: the title of the response (string).
- message: detailed information or error message (string).

Example response:
{
  "success": true,
  "title": "OIDC settings saved",
  "message": "OIDC configurations have been saved successfully."
}
*/

require_once '../../includes/connect_endpoint.php';
require_once __DIR__ . '/../../includes/api_auth.php';
require_once __DIR__ . '/../../includes/admin_roles.php';
require_once '../../includes/ssrf_helper.php';
require_once '../../includes/oidc_settings.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'title' => 'Invalid request method',
        'message' => 'Only POST requests are allowed.'
    ]);
    exit;
}

// Authenticate user first
$user = wallos_api_require($db, 'write', ['requireAdmin' => true]);

$userId = $user['id'];

// Must be an admin
if (!wallos_is_admin($db, $userId)) {
    echo json_encode([
        'success' => false,
        'title' => 'Forbidden',
        'message' => 'Only an admin can update OIDC configurations.'
    ]);
    exit;
}

// 1. Handle OIDC Enabled Toggle
if (isset($_POST['oidc_enabled'])) {
    if (wallos_has_oidc_env_value('OIDC_ENABLED')) {
        echo json_encode([
            'success' => false,
            'title' => 'Environment override',
            'message' => 'OIDC enablement is managed by the OIDC_ENABLED environment variable.'
        ]);
        exit;
    }

    $oidcEnabled = ($_POST['oidc_enabled'] === '1' || $_POST['oidc_enabled'] === 1) ? 1 : 0;
    $stmtEnabled = $db->prepare('UPDATE admin SET oidc_oauth_enabled = :oidcEnabled WHERE id = 1');
    $stmtEnabled->bindParam(':oidcEnabled', $oidcEnabled, SQLITE3_INTEGER);
    $stmtEnabled->execute();
}

// 2. Handle OIDC detailed configurations
$oidcConfiguration = wallos_get_effective_oidc_configuration($db);
$managedFields = $oidcConfiguration['managed_fields'];
$dbSettings = wallos_get_db_oidc_settings($db);

$incomingMapping = [
    'name' => $_POST['name'] ?? null,
    'client_id' => $_POST['client_id'] ?? null,
    'client_secret' => $_POST['client_secret'] ?? null,
    'authorization_url' => $_POST['authorization_url'] ?? null,
    'token_url' => $_POST['token_url'] ?? null,
    'user_info_url' => $_POST['user_info_url'] ?? null,
    'redirect_url' => $_POST['redirect_url'] ?? null,
    'logout_url' => $_POST['logout_url'] ?? null,
    'user_identifier_field' => $_POST['user_identifier_field'] ?? null,
    'scopes' => $_POST['scopes'] ?? null,
    'auth_style' => $_POST['auth_style'] ?? null,
    'auto_create_user' => isset($_POST['auto_create_user']) ? intval($_POST['auto_create_user']) : null,
    'password_login_disabled' => isset($_POST['password_login_disabled']) ? intval($_POST['password_login_disabled']) : null,
    'require_email_verified' => isset($_POST['require_email_verified']) ? intval($_POST['require_email_verified']) : null,
    'roles_claim' => isset($_POST['roles_claim']) ? trim((string) $_POST['roles_claim']) : null,
    'admin_values' => isset($_POST['admin_values']) ? implode(',', wallos_oidc_parse_admin_values($_POST['admin_values'])) : null,
];
$wasPasswordLoginDisabled = (int) $dbSettings['password_login_disabled'] === 1;

// Merge if not managed by environment
$hasConfigChange = false;
foreach ($incomingMapping as $field => $value) {
    if ($value !== null) {
        if (isset($managedFields[$field])) {
            // Cannot modify field overridden by environment variables
            continue;
        }
        $dbSettings[$field] = $value;
        $hasConfigChange = true;
    }
}

if ($hasConfigChange) {
    // SSRF validations
    if ($dbSettings['token_url'] && validate_oidc_endpoint_url($dbSettings['token_url'], $db) === false) {
        echo json_encode([
            "success" => false,
            "title" => "Security Error",
            "message" => "Security Error: Token URL must not target link-local or loopback addresses."
        ]);
        exit;
    }

    if ($dbSettings['user_info_url'] && validate_oidc_endpoint_url($dbSettings['user_info_url'], $db) === false) {
        echo json_encode([
            "success" => false,
            "title" => "Security Error",
            "message" => "Security Error: User Info URL must not target link-local or loopback addresses."
        ]);
        exit;
    }

    if (!$wasPasswordLoginDisabled && (int) $dbSettings['password_login_disabled'] === 1
        && !wallos_password_login_disable_allowed($db)) {
        echo json_encode([
            'success' => false,
            'title' => 'Not allowed',
            'message' => 'Password login can only be disabled while at least one admin account is linked to OIDC.'
        ]);
        exit;
    }

    // Save to oauth_settings table
    $resultSave = wallos_save_db_oidc_settings($db, $dbSettings);
    if (!$resultSave) {
        echo json_encode([
            'success' => false,
            'title' => 'Database error',
            'message' => 'Failed to save OIDC configurations.'
        ]);
        exit;
    }
}

echo json_encode([
    'success' => true,
    'title' => 'OIDC settings saved',
    'message' => 'OIDC configurations have been saved successfully.'
]);

$db->close();
?>
