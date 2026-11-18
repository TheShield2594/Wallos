<?php
/*
  Staging folder for restoring and importing backups.

  Uploaded archives are unpacked here before the database and logos are
  moved into place. It sits in the system temp folder, outside the web root,
  so nothing extracted from an archive can ever be requested over HTTP, and
  so the app also works with a read-only root filesystem (only /tmp and the
  two data volumes need to be writable).
*/

function wallos_restore_staging_dir(): string
{
    $dir = rtrim(sys_get_temp_dir(), '/\\') . '/tally-restore';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir;
}
