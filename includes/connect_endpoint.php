<?php

$databaseFile = '../../db/wallos.db';

require_once __DIR__ . '/db_lock.php';
if (!wallos_db_lock_shared(dirname($databaseFile))) {
    http_response_code(503);
    die(json_encode([
        "success" => false,
        "message" => "The database is being restored. Try again in a moment."
    ]));
}

$db = new SQLite3($databaseFile);
$db->busyTimeout(5000);
// WAL lets readers carry on while a write is in progress; in the default
// rollback journal a writer blocks them and requests fail with "database
// table is locked". The mode is stored in the file, so this is a no-op once set.
$db->exec('PRAGMA journal_mode=WAL');

if (!$db) {
    die('Connection to the database failed.');
}

require_once 'i18n/languages.php';
require_once 'i18n/getlang.php';
require_once 'i18n/' . $lang . '.php';
require_once 'remember_me.php';

$secondsInMonth = 30 * 24 * 60 * 60;
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => $secondsInMonth,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    $userId = $_SESSION['userId'];
} else {
    // The PHP session can be garbage-collected (default ~24 min) long before
    // the "remember me" cookie should expire (30 days). Fall back to it here,
    // the same way full page loads do via checksession.php, so AJAX/API
    // endpoints don't silently behave as logged-out after an idle period.
    $restoredUser = restoreSessionFromRememberMeCookie($db);
    $userId = $restoredUser !== false ? $restoredUser['id'] : 0;
}

?>