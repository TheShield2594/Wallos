<?php
require_once 'validate.php';
require_once __DIR__ . '/../../includes/connect_endpoint_crontabs.php';
require_once __DIR__ . '/../../includes/currency_rates.php';

require 'settimezone.php';
require_once __DIR__ . '/../../includes/monthly_cost.php';
require_once __DIR__ . '/../../includes/bundles.php';

if (php_sapi_name() == 'cli') {
    $date = new DateTime('now');
    echo "\n" . $date->format('Y-m-d') . " " . $date->format('H:i:s') . "<br />\n";
}

$currentDate = new DateTime();
$currentDateString = $currentDate->format('Y-m-d');

function getPriceConverted($price, $currency, $database, $userId)
{
  return wallos_convert_price($price, $currency, $database, $userId);
}

// Get all users

$query = "SELECT id, main_currency FROM user";
$stmt = $db->prepare($query);
$result = $stmt->execute();

while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $userId = $row['id'];
    $userCurrencyId = $row['main_currency'];
    $totalYearlyCost = 0;

    // Subscriptions included in a parent bill are paid through it (issue #24).
    $query = "SELECT * FROM subscriptions WHERE user_id = :userId AND inactive = 0 AND " . wallos_standalone_sql();
    $stmt = $db->prepare($query);
    $stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
    $resultSubscriptions = $stmt->execute();

    while ($rowSubscriptions = $resultSubscriptions->fetchArray(SQLITE3_ASSOC)) {
        $originalSubscriptionPrice = getPriceConverted($rowSubscriptions['price'], $rowSubscriptions['currency_id'], $db, $userId);
        $price = getPricePerMonth($rowSubscriptions['cycle'], $rowSubscriptions['frequency'], $originalSubscriptionPrice, wallos_cycle_active_months($rowSubscriptions)) * 12;
        $totalYearlyCost += $price;
    }

    $query = "INSERT INTO total_yearly_cost (user_id, date, cost, currency) VALUES (:userId, :date, :cost, :currency)";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
    $stmt->bindParam(':date', $currentDateString, SQLITE3_TEXT);
    $stmt->bindParam(':cost', $totalYearlyCost, SQLITE3_FLOAT);
    $stmt->bindParam(':currency', $userCurrencyId, SQLITE3_INTEGER);

    if ($stmt->execute()) {
        echo "Inserted total yearly cost for user " . $userId . " with cost " . $totalYearlyCost . "<br />\n";
    } else {
        echo "Error inserting total yearly cost for user " . $userId . "<br />\n";
    }
}








?>