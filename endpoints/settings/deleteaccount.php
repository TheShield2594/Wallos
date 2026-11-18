<?php

require_once '../../includes/connect_endpoint.php';
require_once __DIR__ . '/../../includes/account_deletion.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/logo_cleanup.php';

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

$userIdToDelete = $data['userId'];

if ($userIdToDelete == 1 || $userIdToDelete != $userId) {
    die(json_encode([
        "success" => false,
        "message" => translate('error', $i18n)
    ]));
} else {
    // Collect this account's uploaded logo files before its rows go, so they
    // can be removed from disk afterwards if nothing else references them.
    $logoFilesToCheck = [];
    $logoQuery = $db->prepare('SELECT logo, logo_variant FROM subscriptions WHERE user_id = :id');
    $logoQuery->bindValue(':id', $userIdToDelete, SQLITE3_INTEGER);
    $logoResult = $logoQuery->execute();
    while ($logoResult && ($logoRow = $logoResult->fetchArray(SQLITE3_ASSOC))) {
        $logoFilesToCheck[] = $logoRow['logo'];
        $logoFilesToCheck[] = $logoRow['logo_variant'];
    }
    $iconQuery = $db->prepare("SELECT icon FROM payment_methods WHERE user_id = :id AND icon NOT LIKE 'images/uploads/icons/%'");
    $iconQuery->bindValue(':id', $userIdToDelete, SQLITE3_INTEGER);
    $iconResult = $iconQuery->execute();
    while ($iconResult && ($iconRow = $iconResult->fetchArray(SQLITE3_ASSOC))) {
        $logoFilesToCheck[] = $iconRow['icon'];
    }

    // Every row the account owns goes in one transaction (see
    // includes/account_deletion.php): if any delete fails, nothing is deleted
    // and the user can try again. A household the account owns goes too (its
    // members' shared subscriptions become private again), as does any other
    // membership or invite.
    //
    // The user table has no AUTOINCREMENT, so SQLite hands a deleted id
    // straight back to the next account created; anything left behind here
    // (a remember-me or password reset token, say) would be inherited by it.
    $deleted = wallos_delete_account($db, $userIdToDelete, [
        'DELETE FROM user WHERE id = :id',
        'DELETE FROM household_members WHERE user_id = :id',
        'DELETE FROM subscriptions WHERE user_id = :id',
        'DELETE FROM subscription_payments WHERE user_id = :id',
        'DELETE FROM subscription_price_changes WHERE user_id = :id',
        'DELETE FROM subscription_shares WHERE user_id = :id',
        'DELETE FROM settings WHERE user_id = :id',
        'DELETE FROM fixer WHERE user_id = :id',
        'DELETE FROM fixer_keys WHERE user_id = :id',
        'DELETE FROM custom_colors WHERE user_id = :id',
        'DELETE FROM currencies WHERE user_id = :id',
        'DELETE FROM categories WHERE user_id = :id',
        'DELETE FROM household WHERE user_id = :id',
        'DELETE FROM payment_methods WHERE user_id = :id',
        'DELETE FROM email_notifications WHERE user_id = :id',
        'DELETE FROM telegram_notifications WHERE user_id = :id',
        'DELETE FROM webhook_notifications WHERE user_id = :id',
        'DELETE FROM gotify_notifications WHERE user_id = :id',
        'DELETE FROM pushover_notifications WHERE user_id = :id',
        'DELETE FROM notification_settings WHERE user_id = :id',
        'DELETE FROM last_exchange_update WHERE user_id = :id',
        'DELETE FROM email_verification WHERE user_id = :id',
        'DELETE FROM totp WHERE user_id = :id',
        'DELETE FROM total_yearly_cost WHERE user_id = :id',
        'DELETE FROM login_tokens WHERE user_id = :id',
        'DELETE FROM password_resets WHERE user_id = :id',
        'DELETE FROM custom_css_style WHERE user_id = :id',
        'DELETE FROM uploaded_avatars WHERE user_id = :id',
        'DELETE FROM ai_settings WHERE user_id = :id',
        'DELETE FROM ai_recommendations WHERE user_id = :id',
        'DELETE FROM google_search WHERE user_id = :id',
        'DELETE FROM ntfy_notifications WHERE user_id = :id',
        'DELETE FROM mattermost_notifications WHERE user_id = :id',
        'DELETE FROM discord_notifications WHERE user_id = :id',
        'DELETE FROM pushplus_notifications WHERE user_id = :id',
        'DELETE FROM serverchan_notifications WHERE user_id = :id',
        'DELETE FROM push_notifications WHERE user_id = :id',
        'DELETE FROM push_subscriptions WHERE user_id = :id',
        'DELETE FROM apprise_notifications WHERE user_id = :id',
        'DELETE FROM notification_templates WHERE user_id = :id',
        'DELETE FROM notification_log WHERE user_id = :id',
        'DELETE FROM notification_recipient_log WHERE user_id = :id OR owner_user_id = :id',
    ]);

    if (!$deleted) {
        die(json_encode([
            "success" => false,
            "message" => translate('error', $i18n)
        ]));
    }

    // The account's rows are committed as gone; drop its logo files that nothing else uses.
    foreach (array_unique(array_filter($logoFilesToCheck)) as $logoFile) {
        deleteLogoFileIfUnused($db, $logoFile, '../../images/uploads/logos/');
    }

    die(json_encode([
        "success" => true,
        "message" => translate('success', $i18n)
    ]));

}
