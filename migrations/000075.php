<?php
/*
  Service descriptions for AI recommendations (issue #42).

  subscriptions.description          optional "What is this?" text (at most
                                     200 characters) sent to the AI model so it
                                     does not have to guess what an obscure
                                     service is.
  ai_settings.include_descriptions   send descriptions and website domains
                                     with the subscriptions (1, the default) or
                                     only name, price, frequency, category and
                                     payer (0).

  Safe to run twice: a failed statement throws, so the runner does not
  record the migration.
*/

$migrate = function ($sql) use ($db) {
    if ($db->exec($sql) === false) {
        throw new RuntimeException('Migration 000075 failed: ' . $db->lastErrorMsg());
    }
};

$hasColumn = function ($table, $column) use ($db) {
    $result = $db->query("SELECT 1 FROM pragma_table_info('" . $table . "') WHERE name = '" . $column . "'");
    if ($result === false) {
        throw new RuntimeException('Migration 000075 failed: ' . $db->lastErrorMsg());
    }

    return $result->fetchArray(SQLITE3_NUM) !== false;
};

if (!$hasColumn('subscriptions', 'description')) {
    $migrate("ALTER TABLE subscriptions ADD COLUMN description TEXT DEFAULT ''");
}
if (!$hasColumn('ai_settings', 'include_descriptions')) {
    $migrate("ALTER TABLE ai_settings ADD COLUMN include_descriptions INTEGER DEFAULT 1");
}
