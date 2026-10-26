<?php
// Keeps database connections away while restore or import swaps wallos.db.
//
// Every connection holds a shared flock() on db/wallos.lock for the rest of
// its request, released when PHP closes the handle at the end of the request.
// Restore and import upgrade theirs to exclusive before they remove the old
// WAL files and rename the new database in. Without this, a request that
// still has the old database open keeps writing to it, and can re-create a
// -wal file that SQLite would then replay onto the restored database.

const WALLOS_DB_LOCK_FILE = 'wallos.lock';

/**
 * @param resource $handle
 */
function wallos_db_lock_wait($handle, $operation, $timeoutSeconds)
{
    $deadline = microtime(true) + $timeoutSeconds;
    while (!flock($handle, $operation | LOCK_NB)) {
        if (microtime(true) >= $deadline) {
            return false;
        }
        usleep(100000);
    }

    return true;
}

/**
 * @return resource|false
 */
function wallos_db_lock_open($databaseDirectory)
{
    $path = rtrim($databaseDirectory, '/') . '/' . WALLOS_DB_LOCK_FILE;

    // Created by whichever process connects first. If that was root (the
    // startup scripts or cron), www-data can't open it for writing, but
    // flock() works on a read-only handle too.
    // Callers such as migrate.php turn every warning into an exception, even
    // a silenced one, so failures are caught rather than left to bubble up.
    foreach (file_exists($path) && !is_writable($path) ? ['r'] : ['c', 'r'] as $mode) {
        try {
            $handle = @fopen($path, $mode);
        } catch (Throwable $error) {
            $handle = false;
        }
        if ($handle !== false) {
            return $handle;
        }
    }

    return false;
}

/**
 * Takes the shared lock for this request. Waits while a restore holds it
 * exclusively, and returns false if that takes longer than the timeout.
 */
function wallos_db_lock_shared($databaseDirectory, $timeoutSeconds = 30)
{
    $handle = wallos_db_lock_open($databaseDirectory);
    if ($handle === false) {
        // Don't take the app down over a lock file it can't create; restore
        // still refuses to run without one (see wallos_db_lock_exclusive).
        error_log('Tally: could not open the database lock file in ' . $databaseDirectory);
        return true;
    }

    if (!wallos_db_lock_wait($handle, LOCK_SH, $timeoutSeconds)) {
        fclose($handle);
        return false;
    }

    $GLOBALS['wallos_db_lock'] = $handle;
    return true;
}

/**
 * Upgrades this request's lock to exclusive, waiting for every other
 * connection to finish. Returns false if they don't within the timeout.
 */
function wallos_db_lock_exclusive($databaseDirectory, $timeoutSeconds = 30)
{
    $handle = $GLOBALS['wallos_db_lock'] ?? wallos_db_lock_open($databaseDirectory);
    if ($handle === false) {
        return false;
    }
    $GLOBALS['wallos_db_lock'] = $handle;

    return wallos_db_lock_wait($handle, LOCK_EX, $timeoutSeconds);
}

/**
 * Lets other connections back in after an exclusive section.
 */
function wallos_db_lock_release_exclusive()
{
    if (isset($GLOBALS['wallos_db_lock'])) {
        flock($GLOBALS['wallos_db_lock'], LOCK_SH);
    }
}
