<?php

/**
 * Whether the renewal reminder for one subscription is due on this cron run.
 *
 * A reminder used to go out only when the days left matched the configured
 * lead time exactly, so a cron that did not run on that one day (container
 * down, host asleep, an earlier crash) skipped the reminder for the whole
 * cycle. Now it is due on any day inside the lead time, once per payment date:
 * $lastNotifiedPayment is the next_payment value the last reminder was sent
 * for, and a new cycle has a new next_payment, so it is due again then.
 *
 * @param int         $daysUntil           calendar days from today to the payment (0 = today)
 * @param int|null    $daysBefore          how many days before the payment to remind
 * @param string      $nextPayment         the payment date, Y-m-d
 * @param string      $today               today, Y-m-d
 * @param string|null $lastNotifiedPayment
 * @return bool
 */
function isRenewalReminderDue($daysUntil, $daysBefore, $nextPayment, $today, $lastNotifiedPayment)
{
    if ($daysBefore === null || $nextPayment < $today) {
        return false;
    }

    if ($daysUntil > (int) $daysBefore) {
        return false;
    }

    return $lastNotifiedPayment !== $nextPayment;
}

?>
