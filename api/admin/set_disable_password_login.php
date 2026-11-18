<?php
/*
This API Endpoint accepts POST requests only.
It receives the following parameters:
- api_key: the API key of the user.
- disable: '1' to disable password login, '0' to enable it.

It returns a JSON object with the following properties:
- success: whether the request was successful (boolean).
- title: the title of the response (string).
- message: detailed information or error message (string).

Example response:
{
  "success": true,
  "title": "Updated",
  "message": "Password login has been disabled."
}
*/

require_once '../../includes/connect_endpoint.php';
require_once __DIR__ . '/../../includes/admin_roles.php';
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

$apiKey = $_POST['api_key'] ?? null;

// Authenticate user first
if (!$apiKey) {
    echo json_encode([
        'success' => false,
        'title' => 'Missing API key',
        'message' => 'API key is required.'
    ]);
    exit;
}

$sql = "SELECT * FROM user WHERE api_key = :apiKey";
$stmt = $db->prepare($sql);
$stmt->bindValue(':apiKey', $apiKey);
$result = $stmt->execute();
$user = $result->fetchArray(SQLITE3_ASSOC);

if (!$user || !wallos_is_admin($db, $user['id'])) {
    echo json_encode([
        'success' => false,
        'title' => 'Unauthorized',
        'message' => 'Invalid API key or insufficient privileges.'
    ]);
    exit;
}

// Now check 'disable' parameter only after authentication
$disable = $_POST['disable'] ?? null;
if (!isset($disable)) {
    echo json_encode([
        'success' => false,
        'title' => 'Missing parameter',
        'message' => 'Parameter "disable" is required.'
    ]);
    exit;
}

if (!in_array($disable, ['0', '1'], true)) {
    echo json_encode([
        'success' => false,
        'title' => 'Invalid parameter',
        'message' => 'Parameter "disable" must be "0" or "1".'
    ]);
    exit;
}

if (wallos_has_oidc_env_value('OIDC_DISABLE_PASSWORD_LOGIN') || wallos_force_password_login()) {
    echo json_encode([
        'success' => false,
        'title' => 'Managed by environment',
        'message' => 'Password login is managed by the OIDC_DISABLE_PASSWORD_LOGIN or TALLY_FORCE_PASSWORD_LOGIN environment variable.'
    ]);
    exit;
}

if ($disable === '1' && !wallos_password_login_disable_allowed($db)) {
    echo json_encode([
        'success' => false,
        'title' => 'Not allowed',
        'message' => 'Password login can only be disabled while at least one admin account is linked to OIDC.'
    ]);
    exit;
}

// Update the password_login_disabled setting
$oidcDbSettings = wallos_get_db_oidc_settings($db);
$oidcDbSettings['password_login_disabled'] = intval($disable);
$updateResult = wallos_save_db_oidc_settings($db, $oidcDbSettings);

if ($updateResult) {
    echo json_encode([
        'success' => true,
        'title' => 'Updated',
        'message' => "Password login has been " . ($disable === '1' ? "disabled" : "enabled") . "."
    ]);
} else {
    echo json_encode([
        'success' => false,
        'title' => 'Database error',
        'message' => 'Failed to update the setting.'
    ]);
}

$db->close();
