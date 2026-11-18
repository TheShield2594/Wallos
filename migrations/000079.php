<?php
/*
  Admin role (issue #45).

  user.is_admin                  replaces "user id 1 is the admin". The account
                                 with id 1 becomes the first admin; should it be
                                 missing, the oldest account does, so an
                                 upgraded instance never ends up without one.
  oauth_settings.roles_claim     OIDC claim (ID token or userinfo) that carries
                                 the user's groups or roles.
  oauth_settings.admin_values    comma-separated values of that claim that make
                                 an account an admin.

  Instance-wide settings stay where they were (admin row id 1, oauth_settings
  row id 1): those rows were never tied to user 1.

  Safe to run twice: the admin flag is only seeded when the column is added.
*/

$migrate = function ($sql) use ($db) {
    if ($db->exec($sql) === false) {
        throw new RuntimeException('Migration 000079 failed: ' . $db->lastErrorMsg());
    }
};

$hasColumn = function ($table, $column) use ($db) {
    $result = $db->query("SELECT 1 FROM pragma_table_info('" . $table . "') WHERE name = '" . $column . "'");
    if ($result === false) {
        throw new RuntimeException('Migration 000079 failed: ' . $db->lastErrorMsg());
    }

    return $result->fetchArray(SQLITE3_NUM) !== false;
};

if (!$hasColumn('user', 'is_admin')) {
    $migrate("ALTER TABLE user ADD COLUMN is_admin INTEGER NOT NULL DEFAULT 0");
    $migrate("UPDATE user SET is_admin = 1 WHERE id = 1");
    $migrate("UPDATE user SET is_admin = 1
        WHERE id = (SELECT MIN(id) FROM user)
        AND NOT EXISTS (SELECT 1 FROM user WHERE is_admin = 1)");
}

if (!$hasColumn('oauth_settings', 'roles_claim')) {
    $migrate("ALTER TABLE oauth_settings ADD COLUMN roles_claim TEXT DEFAULT ''");
}
if (!$hasColumn('oauth_settings', 'admin_values')) {
    $migrate("ALTER TABLE oauth_settings ADD COLUMN admin_values TEXT DEFAULT ''");
}
