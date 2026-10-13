<?php

// Tally restarts its version numbers at v1.0.0. An install upgraded from
// Wallos still has the newest upstream Wallos release (v5.x) stored here,
// which would compare as newer than v1.0.0 and show a false "new version
// available" banner. Forget it; checkforupdates.php fills it in again from
// Tally's own releases.
//
// Safe to run twice.

$db->exec("UPDATE admin SET latest_version = NULL");

?>
