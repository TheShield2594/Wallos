<?php

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint_admin.php';
require_once '../../includes/ssrf_helper.php';
require_once '../../includes/oidc_settings.php';

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

$oidcName = isset($data['oidcName']) ? trim($data['oidcName']) : '';
$oidcClientId = isset($data['oidcClientId']) ? trim($data['oidcClientId']) : '';
$oidcClientSecret = isset($data['oidcClientSecret']) ? trim($data['oidcClientSecret']) : '';
$oidcAuthUrl = isset($data['oidcAuthUrl']) ? trim($data['oidcAuthUrl']) : '';
$oidcTokenUrl = isset($data['oidcTokenUrl']) ? trim($data['oidcTokenUrl']) : '';
$oidcUserInfoUrl = isset($data['oidcUserInfoUrl']) ? trim($data['oidcUserInfoUrl']) : '';
$oidcRedirectUrl = isset($data['oidcRedirectUrl']) ? trim($data['oidcRedirectUrl']) : '';
$oidcLogoutUrl = isset($data['oidcLogoutUrl']) ? trim($data['oidcLogoutUrl']) : '';
$oidcUserIdentifierField = isset($data['oidcUserIdentifierField']) ? trim($data['oidcUserIdentifierField']) : '';
$oidcScopes = isset($data['oidcScopes']) ? trim($data['oidcScopes']) : '';
$oidcAuthStyle = isset($data['oidcAuthStyle']) ? trim($data['oidcAuthStyle']) : '';
$oidcAutoCreateUser = isset($data['oidcAutoCreateUser']) ? (int) $data['oidcAutoCreateUser'] : 0;
$oidcPasswordLoginDisabled = isset($data['oidcPasswordLoginDisabled']) ? (int) $data['oidcPasswordLoginDisabled'] : 0;
$oidcRequireEmailVerified = isset($data['oidcRequireEmailVerified']) ? (int) $data['oidcRequireEmailVerified'] : 1;
$oidcRolesClaim = isset($data['oidcRolesClaim']) ? trim((string) $data['oidcRolesClaim']) : '';
$oidcAdminValues = isset($data['oidcAdminValues']) ? trim((string) $data['oidcAdminValues']) : '';

$oidcConfiguration = wallos_get_effective_oidc_configuration($db);
$managedFields = $oidcConfiguration['managed_fields'];
$dbSettings = wallos_get_db_oidc_settings($db);

$submittedSettings = [
    'name' => $oidcName,
    'client_id' => $oidcClientId,
    'client_secret' => $oidcClientSecret,
    'authorization_url' => $oidcAuthUrl,
    'token_url' => $oidcTokenUrl,
    'user_info_url' => $oidcUserInfoUrl,
    'redirect_url' => $oidcRedirectUrl,
    'logout_url' => $oidcLogoutUrl,
    'user_identifier_field' => $oidcUserIdentifierField,
    'scopes' => $oidcScopes,
    'auth_style' => $oidcAuthStyle,
    'auto_create_user' => $oidcAutoCreateUser,
    'password_login_disabled' => $oidcPasswordLoginDisabled,
    'require_email_verified' => $oidcRequireEmailVerified,
    'roles_claim' => $oidcRolesClaim,
    'admin_values' => implode(',', wallos_oidc_parse_admin_values($oidcAdminValues)),
];

$wasPasswordLoginDisabled = (int) $dbSettings['password_login_disabled'] === 1;

foreach ($submittedSettings as $field => $value) {
    if (!isset($managedFields[$field])) {
        $dbSettings[$field] = $value;
    }
}

if ($dbSettings['token_url'] && validate_oidc_endpoint_url($dbSettings['token_url'], $db) === false) {
    die(json_encode([
        "success" => false,
        "message" => "Security Error: Token URL must not target link-local or loopback addresses."
    ]));
}

if ($dbSettings['user_info_url'] && validate_oidc_endpoint_url($dbSettings['user_info_url'], $db) === false) {
    die(json_encode([
        "success" => false,
        "message" => "Security Error: User Info URL must not target link-local or loopback addresses."
    ]));
}

// Turning password login off needs an admin who can still sign in through
// the identity provider; otherwise nobody could reach this page again.
if (!$wasPasswordLoginDisabled && (int) $dbSettings['password_login_disabled'] === 1
    && !wallos_password_login_disable_allowed($db)) {
    die(json_encode([
        "success" => false,
        "message" => translate('password_login_needs_oidc_admin', $i18n)
    ]));
}

$saved = wallos_save_db_oidc_settings($db, $dbSettings);

if ($saved) {
    $db->close();
    die(json_encode([
        "success" => true,
        "message" => translate('success', $i18n)
    ]));
} else {
    $db->close();
    die(json_encode([
        "success" => false,
        "message" => translate('error', $i18n)
    ]));
}
