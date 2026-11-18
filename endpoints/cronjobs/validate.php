<?php

$secondsInMonth = 30 * 24 * 60 * 60;
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => $secondsInMonth,             
        'httponly' => true,          
        'samesite' => 'Lax'          
    ]);
    session_start();
}

$userId = 0;
if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    $userId = $_SESSION['userId'];
}

if (php_sapi_name() !== 'cli') {
    // Run from the admin page: only an admin may trigger a cron job. A
    // read-only connection of its own, since the job opens its database after
    // this check.
    require_once __DIR__ . '/../../includes/admin_roles.php';
    $validateIsAdmin = false;
    if ($userId > 0) {
        try {
            $validateDb = new SQLite3(__DIR__ . '/../../db/wallos.db', SQLITE3_OPEN_READONLY);
            $validateDb->busyTimeout(5000);
            $validateIsAdmin = wallos_is_admin($validateDb, $userId);
            $validateDb->close();
        } catch (Exception $e) {
            $validateIsAdmin = false;
        }
    }
    if (!$validateIsAdmin) {
        die("Unauthorized");
    }
}

?>