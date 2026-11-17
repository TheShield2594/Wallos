<?php

require_once 'validate.php';
require_once __DIR__ . '/../../includes/connect_endpoint_crontabs.php';
require_once __DIR__ . '/../../includes/payment_ledger.php';

require 'settimezone.php';

$date = new DateTime('now');
echo "\n" . $date->format('Y-m-d') . " " . $date->format('H:i:s') . "<br />\n";
echo $timezone . "<br />\n";

$currentDate = new DateTime();
$currentDateString = $currentDate->format('Y-m-d');

// Every active auto-renewing subscription whose payment date has passed:
// advance it along its calendar-anchored schedule, write one ledger row per
// cycle it moved past, and close fixed-term plans after their final payment
// (see includes/recurrence.php and includes/payment_ledger.php).
$query = "SELECT * FROM subscriptions WHERE next_payment < :currentDate AND auto_renew = 1 AND inactive = 0";
$stmt = $db->prepare($query);
$stmt->bindValue(':currentDate', $currentDate->format('Y-m-d'));
$result = $stmt->execute();

$due = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $due[] = $row;
}

foreach ($due as $row) {
    // A row saved before frequency was validated must not stall this loop
    // for every other user: 0 never advances the date, and a negative value
    // or a cycle without a unit (e.g. one-time) is not a valid interval.
    if (!wallos_is_recurring($row)) {
        continue;
    }

    wallos_apply_schedule_advance($db, $row, $currentDate);
}

$formattedDate = $currentDate->format('Y-m-d');

$deleteQuery = "DELETE FROM last_update_next_payment_date";
$deleteStmt = $db->prepare($deleteQuery);
$deleteResult = $deleteStmt->execute();

$query = "INSERT INTO last_update_next_payment_date (date) VALUES (:formattedDate)";
$stmt = $db->prepare($query);
$stmt->bindParam(':formattedDate', $currentDateString, SQLITE3_TEXT);
$result = $stmt->execute();

echo "Updated next payment dates";
?>