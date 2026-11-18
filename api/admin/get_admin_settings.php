<?php
/*
This API Endpoint accepts both POST and GET requests.
It receives the following parameters:
- api_key: the API key of the user (must belong to an admin).
  Send it as "Authorization: Bearer <key>" (or an X-API-Key header); the api_key
  parameter still works but is deprecated. Required scope: admin:read. The key's owner must be an admin.

It returns a JSON object with the following properties:
- success: whether the request was successful (boolean).
- title: the title of the response (string).
- admin_settings: an object containing the admin settings.
- notes: warning messages or additional information (array).

Example response:
{
  "success": true,
  "title": "admin_settings",
  "admin_settings": {
    "registrations_open": 1,
    "max_users": 100,
    "require_email_verification": 1,
    "server_url": "http://example.com",
    "smtp_address": "smtp.example.com",
    "smtp_port": 587,
    "smtp_username": "admin@example.com",
    "smtp_password": "********",
    "from_email": "no-reply@example.com",
    "encryption": "tls",
    "login_disabled": 0,
    "latest_version": "v1.0.0",
    "update_notification": 1,
    "oidc_oauth_enabled": 0,
    "local_webhook_notifications_allowlist": "localhost,127.0.0.1",
    "allow_standard_users_local_webhooks": 0
  },
  "notes": []
}
*/

require_once '../../includes/connect_endpoint.php';
require_once __DIR__ . '/../../includes/api_auth.php';
require_once __DIR__ . '/../../includes/admin_roles.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER["REQUEST_METHOD"] === "POST" || $_SERVER["REQUEST_METHOD"] === "GET") {
    // Get user from API key
    $user = wallos_api_require($db, 'admin:read', ['requireAdmin' => true]);

    $userId = $user['id'];

    if (!wallos_is_admin($db, $userId)) {
        $response = [
            "success" => false,
            "title" => "Invalid user"
        ];
        echo json_encode($response);
        exit;
    }

    $sql = "SELECT * FROM 'admin'";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':userId', $userId);
    $result = $stmt->execute();
    $admin_settings = $result->fetchArray(SQLITE3_ASSOC);

    if ($admin_settings) {
        unset($admin_settings['id']);
        // if the smtp_password is set, hide it
        if (isset($admin_settings['smtp_password'])) {
            $admin_settings['smtp_password'] = "********";
        }
    }

    $response = [
        "success" => true,
        "title" => "admin_settings",
        "admin_settings" => $admin_settings,
        "notes" => []
    ];

    echo json_encode($response);

    $db->close();

} else {
    $response = [
        "success" => false,
        "title" => "Invalid request method"
    ];
    echo json_encode($response);
    exit;
}

?>