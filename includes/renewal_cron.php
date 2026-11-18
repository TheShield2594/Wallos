<?php
/*
  The renewal cron (endpoints/cronjobs/updatenextpayment.php): advances every
  active auto-renewing subscription whose payment date has passed, writes one
  ledger row per cycle it moved past, closes fixed-term plans after their
  final payment, and ends free trials and promos whose end date has come.

  "Passed" is judged on each account's own calendar: the local date in its
  notification time zone (notification_settings.timezone), the same one the
  reminder crons use. An account without a time zone uses the container's,
  as before. So a payment due on the 10th stays the next payment until the
  account's 10th is over, and its day-of reminder at a late "Send at" time
  still sees it. The cron runs every 15 minutes so each account is advanced
  shortly after its local midnight.
*/

require_once __DIR__ . '/payment_ledger.php';
require_once __DIR__ . '/intro_pricing.php';
require_once __DIR__ . '/notifications/schedule.php';
require_once __DIR__ . '/bundles.php';

/**
 * The account's local date (Y-m-d) at $now, in its notification time zone
 * (the container's when it has none).
 *
 * @param array|null $settings a notification_settings row
 */
function wallos_account_local_date($settings, DateTimeInterface $now)
{
    return (new DateTimeImmutable('@' . $now->getTimestamp()))
        ->setTimezone(wallos_notification_timezone($settings))
        ->format('Y-m-d');
}

/**
 * Runs one pass of the renewal cron.
 *
 * @return array{advanced: int, failed: int[], converted: int}
 */
function wallos_run_renewals(SQLite3 $db, ?DateTimeInterface $now = null)
{
    $now = $now ?? new DateTimeImmutable('now');

    // Every account with an active subscription, also one whose user row is
    // gone (its subscriptions renewed before, so they still do).
    $userIds = [];
    $result = $db->query('SELECT DISTINCT user_id FROM subscriptions WHERE inactive = 0');
    while ($result && ($row = $result->fetchArray(SQLITE3_NUM))) {
        $userIds[] = (int) $row[0];
    }

    $localDates = [];
    $failed = [];
    $advanced = 0;
    // Subscriptions included in a parent bill (issue #24) are not advanced on
    // their own: they follow the parent's next payment (wallos_update_schedule()).
    $query = $db->prepare('SELECT * FROM subscriptions WHERE user_id = :userId AND next_payment < :today AND auto_renew = 1 AND inactive = 0 AND ' . wallos_standalone_sql());
    foreach ($userIds as $userId) {
        $today = wallos_account_local_date(wallos_notification_settings_row($db, $userId), $now);
        $localDates[$userId] = $today;

        $query->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $query->bindValue(':today', $today, SQLITE3_TEXT);
        $result = $query->execute();
        $due = [];
        while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
            $due[] = $row;
        }
        $query->reset();

        foreach ($due as $row) {
            // A row saved before frequency was validated must not stall this
            // loop for every other user: 0 never advances the date, and a
            // negative value or a cycle without a unit (e.g. one-time) is not
            // a valid interval.
            if (!wallos_is_recurring($row)) {
                continue;
            }
            if (empty(wallos_apply_schedule_advance($db, $row, new DateTime($today))['success'])) {
                $failed[] = $row;
            } else {
                $advanced++;
            }
        }
    }

    // A row skipped because the database stayed busy (each advance is its
    // own transaction) gets one more try once the rest are done, and is
    // reported if it still fails; the next run picks it up from its stored
    // date.
    $stillFailed = [];
    foreach ($failed as $row) {
        $today = $localDates[(int) $row['user_id']];
        if (empty(wallos_apply_schedule_advance($db, $row, new DateTime($today))['success'])) {
            $stillFailed[] = (int) $row['id'];
        } else {
            $advanced++;
        }
    }

    // Free trials and promos whose end date has come on the account's
    // calendar: the regular price takes over (after the cycles above were
    // recorded at the intro price). The cancellation cron announces each
    // conversion once.
    $converted = 0;
    foreach ($localDates as $userId => $today) {
        $converted += wallos_convert_ended_intros($db, $today, $userId);
    }

    return ['advanced' => $advanced, 'failed' => $stillFailed, 'converted' => $converted];
}
