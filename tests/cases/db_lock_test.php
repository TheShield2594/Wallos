<?php
/*
  Restore and import replace wallos.db while other requests may still have it
  open. includes/db_lock.php makes every connection hold a shared flock() and
  has restore/import upgrade theirs to exclusive before the swap.

  flock() locks belong to the open file, so two handles in this one process
  conflict exactly like two PHP-FPM workers would.
*/

require_once WALLOS_ROOT . '/includes/db_lock.php';

function db_lock_test_directory()
{
    $directory = WALLOS_TEST_TMP . '/db-lock-' . bin2hex(random_bytes(4));
    mkdir($directory, 0700, true);
    unset($GLOBALS['wallos_db_lock']);

    return $directory;
}

function db_lock_test_cleanup($directory)
{
    unset($GLOBALS['wallos_db_lock']);
    @unlink($directory . '/' . WALLOS_DB_LOCK_FILE);
    @rmdir($directory);
}

wallos_test('a restore waits for other connections and gives up if they stay', function () {
    $directory = db_lock_test_directory();

    // Another request is using the database
    $otherRequest = wallos_db_lock_open($directory);
    flock($otherRequest, LOCK_SH);

    assert_true(wallos_db_lock_shared($directory, 1), 'the restore request connects like any other');
    assert_same(false, wallos_db_lock_exclusive($directory, 0.3), 'the swap does not start while another connection is open');

    flock($otherRequest, LOCK_UN);
    assert_true(wallos_db_lock_exclusive($directory, 1), 'the swap starts once the other connection is gone');

    fclose($otherRequest);
    db_lock_test_cleanup($directory);
});

wallos_test('new connections wait while a restore holds the database', function () {
    $directory = db_lock_test_directory();

    $restore = wallos_db_lock_open($directory);
    flock($restore, LOCK_EX);

    assert_same(false, wallos_db_lock_shared($directory, 0.3), 'a request arriving mid-restore does not open the database');
    assert_true(!isset($GLOBALS['wallos_db_lock']), 'a request that timed out holds no lock');

    flock($restore, LOCK_UN);
    assert_true(wallos_db_lock_shared($directory, 1), 'requests connect again once the restore is done');

    fclose($restore);
    db_lock_test_cleanup($directory);
});

wallos_test('releasing the exclusive lock lets other connections back in', function () {
    $directory = db_lock_test_directory();

    assert_true(wallos_db_lock_shared($directory, 1), 'the restore request connects');
    assert_true(wallos_db_lock_exclusive($directory, 1), 'and takes the database for the swap');

    $otherRequest = wallos_db_lock_open($directory);
    assert_same(false, flock($otherRequest, LOCK_SH | LOCK_NB), 'others are kept out during the swap');

    wallos_db_lock_release_exclusive();
    assert_true(flock($otherRequest, LOCK_SH | LOCK_NB), 'others connect after the migrations have run');

    fclose($otherRequest);
    db_lock_test_cleanup($directory);
});

wallos_test('a lock file this user cannot write is still usable', function () {
    $directory = db_lock_test_directory();
    touch($directory . '/' . WALLOS_DB_LOCK_FILE);
    chmod($directory . '/' . WALLOS_DB_LOCK_FILE, 0444);

    if (is_writable($directory . '/' . WALLOS_DB_LOCK_FILE)) {
        // Running as root: permissions don't apply, nothing to check
        db_lock_test_cleanup($directory);
        return;
    }

    assert_true(wallos_db_lock_open($directory) !== false, 'falls back to a read-only handle, which flock() accepts');
    db_lock_test_cleanup($directory);
});
