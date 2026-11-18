<?php
require_once __DIR__ . '/validate_endpoint.php';
require_once __DIR__ . '/admin_roles.php';
// Check that user is an admin
if (!wallos_is_admin($db, $userId)) {
    die(json_encode([
        "success" => false,
        "message" => translate('error', $i18n)
    ]));
}