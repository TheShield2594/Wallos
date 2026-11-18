<?php

if (!isset($userData)) {
    die("User data missing for OIDC login.");
}

$userId = $userData['id'];

// Role mapping (issue #45): when a roles claim and admin values are set, the
// identity provider decides who is an admin, on every login. The ID token
// came straight from the token endpoint, so its claims are read as-is. The
// last admin is never demoted this way (see wallos_oidc_sync_admin()).
if (isset($oidcSettings, $userInfo)) {
    require_once __DIR__ . '/../admin_roles.php';
    $oidcShouldBeAdmin = wallos_oidc_resolve_admin(
        wallos_oidc_decode_id_token_claims($tokenData['id_token'] ?? null),
        $userInfo,
        $oidcSettings['roles_claim'] ?? '',
        $oidcSettings['admin_values'] ?? ''
    );
    wallos_oidc_sync_admin($db, $userId, $oidcShouldBeAdmin);
}
$username = $userData['username'];
$language = $userData['language'];
$main_currency = $userData['main_currency'];

session_regenerate_id(true);
$_SESSION['username'] = $username;
$_SESSION['loggedin'] = true;
$_SESSION['main_currency'] = $main_currency;
$_SESSION['userId'] = $userId;
$_SESSION['from_oidc'] = true; // Indicate this session is from OIDC login
// Kept for logout, where providers want it as id_token_hint
if (isset($tokenData['id_token']) && is_string($tokenData['id_token'])) {
    $_SESSION['oidc_id_token'] = $tokenData['id_token'];
}

$cookieExpire = time() + (86400 * 30); // 30 days

// generate remember token
$token = bin2hex(random_bytes(32));
$addLoginTokens = "INSERT INTO login_tokens (user_id, token) VALUES (:userId, :token)";
$addLoginTokensStmt = $db->prepare($addLoginTokens);
$addLoginTokensStmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
$addLoginTokensStmt->bindParam(':token', $token, SQLITE3_TEXT);
$addLoginTokensStmt->execute();

$_SESSION['token'] = $token;
$cookieValue = $username . "|" . $token . "|" . $main_currency;
setcookie('wallos_login', $cookieValue, [
    'expires' => $cookieExpire,
    'samesite' => 'Lax',
    'httponly' => true,
]);

// Set language cookie
setcookie('language', $language, [
    'expires' => $cookieExpire,
    'samesite' => 'Lax'
]);

// Set sort order default
if (!isset($_COOKIE['sortOrder'])) {
    setcookie('sortOrder', 'next_payment', [
        'expires' => $cookieExpire,
        'samesite' => 'Lax'
    ]);
}

// Set color theme
$query = "SELECT color_theme FROM settings WHERE user_id = :userId";
$stmt = $db->prepare($query);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
$settings = $result->fetchArray(SQLITE3_ASSOC);
setcookie('colorTheme', $settings['color_theme'], [
    'expires' => $cookieExpire,
    'samesite' => 'Lax'
]);

// Done
$db->close();
header("Location: .");
exit();
