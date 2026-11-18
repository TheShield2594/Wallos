<?php
/*
  Fixtures for the notification tests: an environment whose HTTP and SSRF
  checks are fakes that record what a channel would send, and a few rows.
*/

require_once WALLOS_ROOT . '/includes/notifications/dispatch.php';
require_once WALLOS_ROOT . '/includes/notifications/schedule.php';
require_once WALLOS_ROOT . '/includes/notifications/preview.php';

/**
 * An environment that answers every request with $answer (or a callable),
 * and records the requests in $requests.
 */
function notification_test_env(SQLite3 $db, $userId, array &$requests, $answer = null, array &$log = null)
{
    $answer = $answer ?? ['ok' => true, 'code' => 200, 'body' => '{"ok":true,"code":200}', 'error' => ''];

    return new WallosNotifyEnv($db, $userId, [
        'httpTransport' => function (array $request) use (&$requests, $answer) {
            $requests[] = $request;

            return is_callable($answer) ? $answer($request) : $answer;
        },
        'ssrfCheck' => function ($url) {
            $host = parse_url($url, PHP_URL_HOST);
            if (!$host || $host === 'blocked.example') {
                return false;
            }

            return ['host' => $host, 'ip' => '203.0.113.10', 'port' => 443];
        },
        'smtpCheck' => fn($host) => $host === 'blocked.example' ? false : '203.0.113.25',
        'logger' => function ($line) use (&$log) {
            if (is_array($log)) {
                $log[] = $line;
            }
        },
        'throttle' => false,
    ]);
}

function notification_test_household(SQLite3 $db, $userId, $id, $name, $email = '')
{
    $stmt = $db->prepare('INSERT OR REPLACE INTO household (id, name, email, user_id) VALUES (:id, :name, :email, :userId)');
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->bindValue(':name', $name, SQLITE3_TEXT);
    $stmt->bindValue(':email', $email, SQLITE3_TEXT);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $stmt->execute();
}

/**
 * Inserts a subscription; returns its id.
 */
function notification_test_subscription(SQLite3 $db, $userId, array $fields)
{
    $fields = array_merge([
        'name' => 'Sub',
        'price' => 9.99,
        'currency_id' => wallos_test_currency_id($userId, 0),
        'next_payment' => date('Y-m-d'),
        'start_date' => '2020-01-01',
        'cycle' => 3,
        'frequency' => 1,
        'inactive' => 0,
        'notify' => 1,
        'notify_days_before' => -1,
        'auto_renew' => 1,
        'payer_user_id' => 1,
        'category_id' => 1,
        'user_id' => $userId,
    ], $fields);
    $columns = array_keys($fields);
    $stmt = $db->prepare('INSERT INTO subscriptions (' . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')');
    foreach ($fields as $column => $value) {
        $stmt->bindValue(':' . $column, $value, $value === null ? SQLITE3_NULL : (is_int($value) ? SQLITE3_INTEGER : (is_float($value) ? SQLITE3_FLOAT : SQLITE3_TEXT)));
    }
    $stmt->execute();

    return $db->lastInsertRowID();
}

function notification_test_enable(SQLite3 $db, $table, $userId, array $columns)
{
    $columns = array_merge(['user_id' => $userId, 'enabled' => 1], $columns);
    $names = array_keys($columns);
    $stmt = $db->prepare('INSERT INTO ' . $table . ' (' . implode(', ', $names) . ') VALUES (:' . implode(', :', $names) . ')');
    foreach ($columns as $name => $value) {
        $stmt->bindValue(':' . $name, $value);
    }
    $stmt->execute();
}

function notification_test_date($offsetDays, $from = null)
{
    return (new DateTime($from ?? 'today'))->modify(sprintf('%+d days', $offsetDays))->format('Y-m-d');
}
