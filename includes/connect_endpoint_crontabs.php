<?php

$databaseFile = __DIR__ . '/../db/wallos.db';

require_once __DIR__ . '/db_lock.php';
if (!wallos_db_lock_shared(dirname($databaseFile))) {
    die("The database is being restored; skipping this run.\n");
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

require_once __DIR__ . '/../includes/i18n/languages.php';
require_once __DIR__ . '/../includes/i18n/getlang.php';
require_once __DIR__ . '/../includes/i18n/' . $lang . '.php';

?>