<?php
require_once 'remember_me.php';

// Handle OIDC first
$secondsInMonth = 30 * 24 * 60 * 60;
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => $secondsInMonth,             
        'httponly' => true,          
        'samesite' => 'Lax'          
    ]);
    session_start();
}

if (isset($_GET['code']) && isset($_GET['state'])) {
    // This request is coming from the OIDC login flow
    $code = $_GET['code'];
    $state = $_GET['state'];
    $expectedState = $_SESSION['oidc_state'] ?? null;

    if (
        !is_string($code) || $code === '' ||
        !is_string($state) || $state === '' ||
        !is_string($expectedState) || $expectedState === '' ||
        !hash_equals($expectedState, $state)
    ) {
        unset($_SESSION['oidc_state']);
        $db->close();

        // A retried or duplicate callback (browser retry, prefetch) arrives
        // after the first request already consumed the state. If that first
        // request signed the user in, send them on instead of showing an error.
        if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
            header("Location: .");
        } else {
            header("Location: login.php?error=oidc_invalid_state");
        }
        exit();
    }

    // Consume the state and write the session back right away. The token
    // exchange below is slow; without this, a retried callback still sees the
    // state, redeems the same code a second time and the IdP rejects it.
    unset($_SESSION['oidc_state']);
    session_write_close();
    session_start();

    require_once 'includes/oidc/handle_oidc_callback.php';

} else {
    if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
        $username = $_SESSION['username'];
        $main_currency = $_SESSION['main_currency'];
        $sql = "SELECT * FROM user WHERE username = :username";
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':username', $username, SQLITE3_TEXT);
        $result = $stmt->execute();
        $userData = $result->fetchArray(SQLITE3_ASSOC);
        $userId = $userData['id'];

        if ($userData === false) {
            header('Location: logout.php');
            exit();
        } else {
            $_SESSION['userId'] = $userData['id'];
        }

        if ($userData['avatar'] == "") {
            $userData['avatar'] = "0";
        }
    } else {
        if (!isset($_COOKIE['wallos_login'])) {
            $db->close();
            header("Location: login.php");
            exit();
        }

        $userData = restoreSessionFromRememberMeCookie($db);
        if ($userData === false) {
            $db->close();
            header("Location: logout.php");
            exit();
        }

        if ($userData['avatar'] == "") {
            $userData['avatar'] = "0";
        }
        $userId = $userData['id'];
        $main_currency = $userData['main_currency'];
    }
}


?>