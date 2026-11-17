<?php

$currentPage = basename($_SERVER['PHP_SELF']);
if ($currentPage == 'index.php') {
    // Redirect to subscriptions page if no subscriptions exist
    $stmt = $db->prepare("SELECT COUNT(*) FROM subscriptions WHERE user_id = :userId");
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_NUM);
    $subscriptionCount = $row[0];

    // Subscriptions shared with the account's household count too.
    if ($subscriptionCount === 0) {
        require_once __DIR__ . '/households.php';
        $subscriptionCount = count(wallos_shared_subscriptions($db, $userId));
    }

    if ($subscriptionCount === 0) {
        header('Location: subscriptions.php');
        exit;
    }
}