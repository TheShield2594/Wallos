<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/markdown.php';
require_once '../../includes/payment_ledger.php';
require_once '../../includes/cancellation_deadline.php';
require_once '../../includes/intro_pricing.php';
require_once '../../includes/households.php';
require_once '../../includes/cost_sharing.php';
require_once '../../includes/bundles.php';

if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    if (isset($_GET['id']) && $_GET['id'] != "") {
        $subscriptionId = intval($_GET['id']);
        // The owner's own subscription, or one shared with their household.
        $row = wallos_load_accessible_subscription($db, $userId, $subscriptionId, 'read');
        $isShared = $row !== false && $row['access'] !== 'owner';
        if ($isShared) {
            $sharedRow = null;
            foreach (wallos_shared_subscriptions($db, $userId) as $candidate) {
                if ((int) $candidate['id'] === $subscriptionId) {
                    $sharedRow = $candidate;
                    break;
                }
            }
            $row = $sharedRow === null ? false : array_merge($sharedRow, ['access' => $row['access']]);
        }

        $subscriptionData = array();

        if ($row !== false) {
            $subscriptionData['id'] = $subscriptionId;
            $subscriptionData['name'] = htmlspecialchars_decode($row['name'] ?? "");
            $subscriptionData['logo'] = $row['logo'];
            $subscriptionData['logo_text_color'] = $row['logo_text_color'];
            $subscriptionData['logo_variant'] = $row['logo_variant'];
            $subscriptionData['price'] = $row['price'];
            $subscriptionData['tier_name'] = $row['tier_name'] ?? null;
            $subscriptionData['currency_id'] = $row['currency_id'];
            $subscriptionData['auto_renew'] = $row['auto_renew'];
            $subscriptionData['start_date'] = $row['start_date'];
            $subscriptionData['next_payment'] = $row['next_payment'];
            $subscriptionData['frequency'] = $row['frequency'];
            $subscriptionData['cycle'] = $row['cycle'];
            $subscriptionData['active_months'] = wallos_sanitize_active_months($row['active_months'] ?? WALLOS_ALL_MONTHS);
            // Sharing & reimbursement are the owner's own (a reason can name an
            // employer or a tax matter): other accounts in the household don't see them.
            $subscriptionData['reimbursed_percent'] = $isShared ? 0.0 : (float) ($row['reimbursed_percent'] ?? 0);
            $subscriptionData['reimbursed_reason'] = $isShared ? '' : ($row['reimbursed_reason'] ?? '');
            $subscriptionData['shares'] = (object) ($isShared ? [] : (wallos_load_subscription_shares($db, $userId)[(int) $row['id']] ?? []));
            $subscriptionData['notes'] = $row['notes'] ?? "";
            $subscriptionData['notes_html'] = render_notes_markdown($row['notes'] ?? "");
            $subscriptionData['payment_method_id'] = $row['payment_method_id'];
            $subscriptionData['payer_user_id'] = $row['payer_user_id'];
            $subscriptionData['category_id'] = $row['category_id'];
            $subscriptionData['notify'] = $row['notify'];
            $subscriptionData['inactive'] = $row['inactive'];
            $subscriptionData['url'] = htmlspecialchars_decode($row['url'] ?? "");
            $subscriptionData['description'] = (string) ($row['description'] ?? '');
            $subscriptionData['notify_days_before'] = $row['notify_days_before'];
            $subscriptionData['reminder_days'] = $row['reminder_days'] ?? null;
            $subscriptionData['cancellation_notify_days_before'] = (int) ($row['cancellation_notify_days_before'] ?? 0);
            $subscriptionData['mute_payment_until_cancellation'] = (int) ($row['mute_payment_until_cancellation'] ?? 0);
            $subscriptionData['cancellation_date'] = $row['cancellation_date'];
            $subscriptionData['cancel_notice_mode'] = wallos_cancel_mode($row);
            $subscriptionData['cancel_notice_value'] = $row['cancel_notice_value'] === null ? null : (int) $row['cancel_notice_value'];
            $subscriptionData['cancel_notice_unit'] = $row['cancel_notice_unit'] ?? null;
            $subscriptionData['min_term_end'] = $row['min_term_end'] ?? null;
            $deadline = wallos_cancel_deadline($row);
            $subscriptionData['cancel_by'] = $deadline['cancel_by'] ?? null;
            $subscriptionData['cancel_renewal'] = $deadline['renewal'] ?? null;
            $subscriptionData['locked_until'] = wallos_cancel_locked_until($row);
            $subscriptionData['intro_type'] = wallos_has_intro($row) ? $row['intro_type'] : null;
            $subscriptionData['intro_price'] = $row['intro_price'] === null ? null : (float) $row['intro_price'];
            $subscriptionData['intro_end_date'] = $row['intro_end_date'] ?? null;
            $subscriptionData['intro_notify_days'] = (int) ($row['intro_notify_days'] ?? 3);
            $subscriptionData['intro_active'] = wallos_intro_active($row);
            $subscriptionData['current_price'] = wallos_current_price($row);
            $subscriptionData['replacement_subscription_id'] = $row['replacement_subscription_id'];
            // Bundles (issue #24): the bill it is included in, and how many it includes.
            $subscriptionData['parent_subscription_id'] = wallos_is_bundle_child($row) ? (int) $row['parent_subscription_id'] : null;
            $subscriptionData['allocated_amount'] = $row['allocated_amount'] === null ? null : (float) $row['allocated_amount'];
            $bundleContext = wallos_bundle_list_context($db, (int) $row['user_id']);
            // A household member sees the link, not the name of a parent that may be private.
            $subscriptionData['parent_name'] = $subscriptionData['parent_subscription_id'] !== null && !$isShared
                ? htmlspecialchars_decode($bundleContext['names'][$subscriptionData['parent_subscription_id']] ?? '') : null;
            $subscriptionData['child_count'] = $bundleContext['children'][(int) $row['id']] ?? 0;
            $subscriptionData['renew_from_action'] = (int) ($row['renew_from_action'] ?? 0);
            $subscriptionData['variable_amount'] = (int) ($row['variable_amount'] ?? 0);
            $subscriptionData['end_date'] = $row['end_date'] ?? null;
            $subscriptionData['total_payments'] = $row['total_payments'] === null ? null : (int) $row['total_payments'];
            $subscriptionData['payments_made'] = (int) ($row['payments_made'] ?? 0);
            $subscriptionData['ended_reason'] = $row['ended_reason'] ?? null;
            $subscriptionData['ends_mode'] = $row['plan_mode'] ?? (!empty($row['end_date']) ? 'date' : 'never');
            $subscriptionData['billing_anchor_day'] = $row['billing_anchor_day'] === null ? null : (int) $row['billing_anchor_day'];
            $lastPaid = wallos_ledger_last_paid_map($db, (int) $row['user_id'])[(int) $row['id']] ?? null;
            $subscriptionData['paid_badge'] = wallos_paid_badge_date($row, $lastPaid);
            $subscriptionData['paid_due_date'] = $subscriptionData['paid_badge'] !== null ? $lastPaid['due_date'] : null;

            // Household sharing: what the viewer may do, and the owner's
            // category, payer and payment method by name (they are the
            // owner's records, not the viewer's).
            $subscriptionData['access'] = $row['access'];
            $subscriptionData['shared'] = !empty($row['shared_household_id']);
            $subscriptionData['shared_owner_name'] = $isShared ? $row['shared_owner_name'] : null;
            if ($isShared) {
                // Without a currency of the owner's code the price was
                // converted for display, so the form does not offer to edit it.
                $viewerCurrencies = wallos_household_viewer_currencies($db, $userId);
                $subscriptionData['price_editable'] = isset($viewerCurrencies['by_code'][$row['shared_currency_code']]);
                $subscriptionData['category_name'] = $row['shared_category_name'];
                $subscriptionData['payer_name'] = $row['shared_payer_name'];
                $subscriptionData['payment_method_name'] = $row['shared_payment_method_name'];
            }

            $subscriptionJson = json_encode($subscriptionData);
            header('Content-Type: application/json');
            echo $subscriptionJson;
        } else {
            echo translate('error', $i18n);
        }
    } else {
        echo translate('error', $i18n);
    }
} else {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => translate('session_expired', $i18n)]);
}
$db->close();
?>
