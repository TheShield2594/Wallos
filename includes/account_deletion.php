<?php
/*
  Account deletion, all or nothing.

  Both deletion endpoints (settings/deleteaccount.php and admin/deleteuser.php)
  name their tables as literal `DELETE FROM <table>` statements, which
  tests/cases/account_deletion_test.php reads to check every table with a
  user_id is covered. They hand that list here, and it runs inside a single
  write transaction: if any statement fails (a busy timeout, say) nothing has
  been deleted, the user row included, and the caller reports the error so the
  user can try again. Before, each delete committed on its own, so a failure
  half way left the account gone but rows such as stored notification
  credentials behind, and the endpoint still reported success.
*/

require_once __DIR__ . '/households.php';

/**
 * Deletes an account in one transaction.
 *
 * Every statement is prepared with `:id` bound to the account id. A statement
 * on a table this database does not have yet (a migration that has not run)
 * is skipped, since such a table holds nothing to delete; any other failure
 * rolls everything back.
 *
 * @param SQLite3  $db
 * @param int      $userId
 * @param string[] $statements
 * @return bool true when every delete committed, false when nothing changed
 */
function wallos_delete_account(SQLite3 $db, $userId, array $statements)
{
    if (@$db->exec('BEGIN IMMEDIATE') === false) {
        return false;
    }

    try {
        $ok = wallos_household_forget_user($db, $userId);

        foreach ($statements as $sql) {
            if (!$ok) {
                break;
            }
            $ok = wallos_delete_account_statement($db, $userId, $sql);
        }
    } catch (Throwable $error) {
        @$db->exec('ROLLBACK');
        throw $error;
    }

    if (!$ok || @$db->exec('COMMIT') === false) {
        @$db->exec('ROLLBACK');
        return false;
    }

    return true;
}

/**
 * @return bool
 */
function wallos_delete_account_statement(SQLite3 $db, $userId, $sql)
{
    $stmt = @$db->prepare($sql);
    if ($stmt === false) {
        // Only a table that does not exist is tolerated.
        if (preg_match('/DELETE FROM\s+(\w+)/i', $sql, $match)) {
            $check = $db->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :name");
            if ($check !== false) {
                $check->bindValue(':name', $match[1], SQLITE3_TEXT);
                $found = $check->execute();
                if ($found !== false && $found->fetchArray() === false) {
                    return true;
                }
            }
        }

        return false;
    }

    $stmt->bindValue(':id', (int) $userId, SQLITE3_INTEGER);

    return @$stmt->execute() !== false;
}
