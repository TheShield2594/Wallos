<?php
/*
  Mark-as-paid and the payment history, for the account's own subscriptions
  and for ones shared with its household (see includes/households.php).

  A household member sees a shared subscription in their own currency. An
  amount they enter is converted back into the owner's currency before it is
  stored, and the history is converted for them before it is shown. When
  there is no conversion path (the currencies changed while a dialog was
  open), the request is refused rather than stored or shown with a wrong
  amount. The endpoints are thin wrappers around these functions, so the
  tests run the same flow.
*/

require_once __DIR__ . '/payment_ledger.php';
require_once __DIR__ . '/households.php';

/**
 * Marks the current cycle of a subscription as paid on behalf of $userId.
 *
 * @param float|string|null $amount in the currency the subscription is shown in to $userId
 * @return array the outcome of wallos_mark_paid(), or success false with
 *               error 'not_found' (no edit access) or 'no_conversion'
 */
function wallos_member_mark_paid(SQLite3 $db, $userId, $subscriptionId, $amount, DateTimeInterface $today)
{
    // The owner, or a household editor of a shared subscription.
    $subscription = wallos_load_accessible_subscription($db, $userId, $subscriptionId, 'edit');
    if ($subscription === false) {
        return ['success' => false, 'error' => 'not_found'];
    }

    if ($subscription['access'] !== 'owner') {
        $factor = wallos_household_display_factor($db, $userId, $subscription['currency_id']);
        if ($factor === null) {
            return ['success' => false, 'error' => 'no_conversion'];
        }
        if ($amount !== null && is_numeric($amount)) {
            $amount = (float) $amount / $factor;
        }
    }

    return wallos_mark_paid($db, $subscription, $today, $amount);
}

/**
 * The payment history of a subscription as $userId sees it.
 *
 * @return array{success: bool, error?: string, payments?: array}
 */
function wallos_member_ledger_list(SQLite3 $db, $userId, $subscriptionId, $limit = 12)
{
    // Readable by the owner and by the household it is shared with.
    $subscription = wallos_load_accessible_subscription($db, $userId, $subscriptionId, 'read');
    if ($subscription === false) {
        return ['success' => false, 'error' => 'not_found'];
    }

    $payments = wallos_ledger_list($db, (int) $subscription['user_id'], $subscription['id'], $limit);
    if ($subscription['access'] !== 'owner') {
        $factor = wallos_household_display_factor($db, $userId, $subscription['currency_id']);
        if ($factor === null) {
            return ['success' => false, 'error' => 'no_conversion'];
        }
        foreach ($payments as &$payment) {
            if ($payment['amount'] !== null) {
                $payment['amount'] = (float) $payment['amount'] * $factor;
            }
        }
        unset($payment);
    }

    return ['success' => true, 'payments' => $payments];
}
