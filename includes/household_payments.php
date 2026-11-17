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
 * @param int|null $shownCurrencyId the currency the amount was entered in, as the
 *                 form showed it; a member's amount is refused when the mapping
 *                 has moved to another currency since (currencies changed in
 *                 another tab)
 * @return array the outcome of wallos_mark_paid(), or success false with
 *               error 'not_found' (no edit access), 'no_conversion' or
 *               'currency_changed'
 */
function wallos_member_mark_paid(SQLite3 $db, $userId, $subscriptionId, $amount, DateTimeInterface $today, $shownCurrencyId = null)
{
    // The owner, or a household editor of a shared subscription.
    $subscription = wallos_load_accessible_subscription($db, $userId, $subscriptionId, 'edit');
    if ($subscription === false) {
        return ['success' => false, 'error' => 'not_found'];
    }

    if ($subscription['access'] !== 'owner') {
        $mapping = wallos_household_currency_mapping($db, $userId, $subscription['currency_id']);
        if ($mapping === null || $mapping['factor'] <= 0) {
            return ['success' => false, 'error' => 'no_conversion'];
        }
        if ($amount !== null && is_numeric($amount)) {
            if ($shownCurrencyId === null || (int) $shownCurrencyId !== (int) $mapping['currency_id']) {
                return ['success' => false, 'error' => 'currency_changed'];
            }
            $amount = (float) $amount / $mapping['factor'];
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
        // Each payment from the currency it was recorded in (the owner may
        // have changed the subscription's currency since), labelled with the
        // member's currency it was converted into.
        $viewerCurrencies = wallos_household_viewer_currencies($db, $userId);
        // The subscription itself must be expressible for the member, as for
        // mark-as-paid, whatever the history holds.
        if (wallos_household_currency_mapping($db, $userId, $subscription['currency_id'], $viewerCurrencies) === null) {
            return ['success' => false, 'error' => 'no_conversion'];
        }
        $mappings = [];
        foreach ($payments as &$payment) {
            $recorded = (int) ($payment['currency_id'] ?: $subscription['currency_id']);
            if (!array_key_exists($recorded, $mappings)) {
                $mappings[$recorded] = wallos_household_currency_mapping($db, $userId, $recorded, $viewerCurrencies);
            }
            $mapping = $mappings[$recorded];
            if ($mapping === null || $mapping['factor'] <= 0) {
                return ['success' => false, 'error' => 'no_conversion'];
            }
            if ($payment['amount'] !== null) {
                $payment['amount'] = (float) $payment['amount'] * $mapping['factor'];
            }
            $payment['currency_id'] = $mapping['currency_id'];
        }
        unset($payment);
    }

    return ['success' => true, 'payments' => $payments];
}
