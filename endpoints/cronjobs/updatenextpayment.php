<?php

require_once 'validate.php';
require_once __DIR__ . '/../../includes/connect_endpoint_crontabs.php';
require_once __DIR__ . '/../../includes/renewal_cron.php';

require 'settimezone.php';

// Overlapping runs would each read the due rows and write them back in turn,
// so an older run could put back a schedule a newer run already advanced.
// Skip this run when another one holds the lock; the next run recalculates
// from the stored dates. (db/wallos.lock is shared by every connection, so it
// can't serve here.) Without a lock file the run goes ahead as before.
$cronLock = @fopen(__DIR__ . '/../../db/updatenextpayment.lock', 'c');
if ($cronLock !== false && !flock($cronLock, LOCK_EX | LOCK_NB)) {
    echo "Another next payment update is running; skipping this run.\n";
    exit(0);
}

$date = new DateTime('now');
echo "\n" . $date->format('Y-m-d') . " " . $date->format('H:i:s') . "<br />\n";
echo $timezone . "<br />\n";

$currentDate = new DateTime();
$currentDateString = $currentDate->format('Y-m-d');

// Each account's payments advance on its own calendar (its notification time
// zone, or the container's): see includes/renewal_cron.php.
$run = wallos_run_renewals($db);
$stillFailed = $run['failed'];
if ($run['converted'] > 0) {
    echo "Ended " . $run['converted'] . " intro period(s).<br />\n";
}

$formattedDate = $currentDate->format('Y-m-d');

$deleteQuery = "DELETE FROM last_update_next_payment_date";
$deleteStmt = $db->prepare($deleteQuery);
$deleteResult = $deleteStmt->execute();

$query = "INSERT INTO last_update_next_payment_date (date) VALUES (:formattedDate)";
$stmt = $db->prepare($query);
$stmt->bindParam(':formattedDate', $currentDateString, SQLITE3_TEXT);
$result = $stmt->execute();

if ($stillFailed !== []) {
    echo "Could not update " . count($stillFailed) . " subscription(s) because the database was busy (ids: "
        . implode(', ', $stillFailed) . "); they will be retried on the next run.<br />\n";
}
echo "Updated next payment dates";
?>
