<?php
/*
  Category model v2 (see includes/categories.php, issue #31).

  categories.parent_id    a top-level category of the same account, for
                          sub-categories (two levels at most).
  categories.template_id  the admin default category it was created from,
                          so "Apply to all users" adds only what is missing.
  category_templates      the admin's default categories for new accounts.

  Safe to run twice: a failed statement throws, so the runner does not
  record the migration.
*/

$migrate = function ($sql) use ($db) {
    if ($db->exec($sql) === false) {
        throw new RuntimeException('Migration 000073 failed: ' . $db->lastErrorMsg());
    }
};

$hasColumn = function ($table, $column) use ($db) {
    $result = $db->query("SELECT 1 FROM pragma_table_info('" . $table . "') WHERE name = '" . $column . "'");
    if ($result === false) {
        throw new RuntimeException('Migration 000073 failed: ' . $db->lastErrorMsg());
    }

    return $result->fetchArray(SQLITE3_NUM) !== false;
};

if (!$hasColumn('categories', 'parent_id')) {
    $migrate("ALTER TABLE categories ADD COLUMN parent_id INTEGER DEFAULT NULL");
}
if (!$hasColumn('categories', 'template_id')) {
    $migrate("ALTER TABLE categories ADD COLUMN template_id INTEGER DEFAULT NULL");
}
$migrate("CREATE TABLE IF NOT EXISTS category_templates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    parent_template_id INTEGER DEFAULT NULL,
    \"order\" INTEGER DEFAULT 0
)");
$migrate("CREATE INDEX IF NOT EXISTS idx_categories_parent ON categories (user_id, parent_id)");
// One category per default per account, so applying the defaults twice adds nothing.
$migrate("CREATE UNIQUE INDEX IF NOT EXISTS idx_categories_template ON categories (user_id, template_id) WHERE template_id IS NOT NULL");
