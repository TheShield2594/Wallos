<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/markdown.php';
require_once '../../includes/payment_ledger.php';
require_once '../../includes/cancellation_deadline.php';
require_once '../../includes/intro_pricing.php';

if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    if (isset($_GET['id']) && $_GET['id'] != "") {
        $subscriptionId = intval($_GET['id']);
        $query = "SELECT * FROM subscriptions WHERE id = :subscriptionId AND user_id = :userId";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':subscriptionId', $subscriptionId, SQLITE3_INTEGER);
        $stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
        $result = $stmt->execute();

        $subscriptionData = array();

        if ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $subscriptionData['id'] = $subscriptionId;
            $subscriptionData['name'] = htmlspecialchars_decode($row['name'] ?? "");
            $subscriptionData['logo'] = $row['logo'];
            $subscriptionData['logo_text_color'] = $row['logo_text_color'];
            $subscriptionData['logo_variant'] = $row['logo_variant'];
            $subscriptionData['price'] = $row['price'];
            $subscriptionData['currency_id'] = $row['currency_id'];
            $subscriptionData['auto_renew'] = $row['auto_renew'];
            $subscriptionData['start_date'] = $row['start_date'];
            $subscriptionData['next_payment'] = $row['next_payment'];
            $subscriptionData['frequency'] = $row['frequency'];
            $subscriptionData['cycle'] = $row['cycle'];
            $subscriptionData['notes'] = $row['notes'] ?? "";
            $subscriptionData['notes_html'] = render_notes_markdown($row['notes'] ?? "");
            $subscriptionData['payment_method_id'] = $row['payment_method_id'];
            $subscriptionData['payer_user_id'] = $row['payer_user_id'];
            $subscriptionData['category_id'] = $row['category_id'];
            $subscriptionData['notify'] = $row['notify'];
            $subscriptionData['inactive'] = $row['inactive'];
            $subscriptionData['url'] = htmlspecialchars_decode($row['url'] ?? "");
            $subscriptionData['notify_days_before'] = $row['notify_days_before'];
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
            $subscriptionData['renew_from_action'] = (int) ($row['renew_from_action'] ?? 0);
            $subscriptionData['variable_amount'] = (int) ($row['variable_amount'] ?? 0);
            $subscriptionData['end_date'] = $row['end_date'] ?? null;
            $subscriptionData['total_payments'] = $row['total_payments'] === null ? null : (int) $row['total_payments'];
            $subscriptionData['payments_made'] = (int) ($row['payments_made'] ?? 0);
            $subscriptionData['ended_reason'] = $row['ended_reason'] ?? null;
            $subscriptionData['ends_mode'] = $row['plan_mode'] ?? (!empty($row['end_date']) ? 'date' : 'never');
            $subscriptionData['billing_anchor_day'] = $row['billing_anchor_day'] === null ? null : (int) $row['billing_anchor_day'];
            $lastPaid = wallos_ledger_last_paid_map($db, $userId)[(int) $row['id']] ?? null;
            $subscriptionData['paid_badge'] = wallos_paid_badge_date($row, $lastPaid);
            $subscriptionData['paid_due_date'] = $subscriptionData['paid_badge'] !== null ? $lastPaid['due_date'] : null;

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
