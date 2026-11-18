<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/ssrf_helper.php';
require_once '../../includes/notifications/apprise_settings.php';

$data = json_decode(file_get_contents("php://input"), true);
$settings = wallos_apprise_settings_from_input(is_array($data) ? $data : []);
if ($settings['error'] !== null) {
    die(json_encode(["success" => false, "message" => translate($settings['error'], $i18n)]));
}
$fields = $settings['fields'];

if ($fields['enabled']) {
    validate_webhook_url_for_ssrf($fields['url'], $db, $i18n, $userId);
}

$stmt = $db->prepare('INSERT INTO apprise_notifications (user_id, enabled, url, mode, config_key, urls, tags, ignore_ssl)
    VALUES (:userId, :enabled, :url, :mode, :configKey, :urls, :tags, :ignoreSsl)
    ON CONFLICT(user_id) DO UPDATE SET enabled = excluded.enabled, url = excluded.url, mode = excluded.mode,
        config_key = excluded.config_key, urls = excluded.urls, tags = excluded.tags, ignore_ssl = excluded.ignore_ssl');
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$stmt->bindValue(':enabled', $fields['enabled'], SQLITE3_INTEGER);
$stmt->bindValue(':url', $fields['url'], SQLITE3_TEXT);
$stmt->bindValue(':mode', $fields['mode'], SQLITE3_TEXT);
$stmt->bindValue(':configKey', $fields['config_key'], SQLITE3_TEXT);
$stmt->bindValue(':urls', $fields['urls'], SQLITE3_TEXT);
$stmt->bindValue(':tags', $fields['tags'], SQLITE3_TEXT);
$stmt->bindValue(':ignoreSsl', $fields['ignore_ssl'], SQLITE3_INTEGER);

echo json_encode($stmt->execute()
    ? ["success" => true, "message" => translate('notifications_settings_saved', $i18n)]
    : ["success" => false, "message" => translate('error_saving_notifications', $i18n)]);
