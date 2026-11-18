<?php
/*
  Promote or demote an account (issue #45). Admin only, POST with CSRF token.

  Body: {"userId": 2, "isAdmin": true}

  The last admin cannot be demoted. When OIDC role mapping is configured the
  identity provider has the last word: the next login of that account sets
  the flag from its role claim again.
*/

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint_admin.php';

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

$targetUserId = (int) ($data['userId'] ?? 0);
$makeAdmin = filter_var($data['isAdmin'] ?? false, FILTER_VALIDATE_BOOLEAN);

if ($targetUserId <= 0) {
    die(json_encode([
        "success" => false,
        "message" => translate('error', $i18n)
    ]));
}

$result = wallos_set_user_admin($db, $targetUserId, $makeAdmin);
$db->close();

if (!$result['success']) {
    die(json_encode([
        "success" => false,
        "message" => $result['reason'] === 'last_admin'
            ? translate('cannot_remove_last_admin', $i18n)
            : translate('error', $i18n)
    ]));
}

echo json_encode([
    "success" => true,
    "message" => translate('success', $i18n)
]);
