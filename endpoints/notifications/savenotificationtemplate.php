<?php
/*
  Saves or resets a notification message template (issue #37).

  POST JSON: { kind: "payment"|"cancellation", channel: ""|"email"|..., title, body, reset: bool }
  An empty title and body, or reset, removes the template, so the built-in
  default applies again.
*/

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/notifications/dispatch.php';

$data = json_decode(file_get_contents("php://input"), true);
$kind = $data['kind'] ?? '';
$channel = (string) ($data['channel'] ?? '');
$title = str_replace("\r\n", "\n", (string) ($data['title'] ?? ''));
$body = str_replace("\r\n", "\n", (string) ($data['body'] ?? ''));

if (!in_array($kind, WALLOS_NOTIFICATION_KINDS, true)
    || ($channel !== '' && !array_key_exists($channel, wallos_notification_channel_classes()))) {
    die(json_encode(["success" => false, "message" => translate('error', $i18n)]));
}

$delete = $db->prepare("DELETE FROM notification_templates WHERE user_id = :userId AND kind = :kind AND COALESCE(channel, '') = :channel");
$delete->bindValue(':userId', $userId, SQLITE3_INTEGER);
$delete->bindValue(':kind', $kind, SQLITE3_TEXT);
$delete->bindValue(':channel', $channel, SQLITE3_TEXT);

if (!empty($data['reset']) || (trim($title) === '' && trim($body) === '')) {
    $delete->execute();
    die(json_encode(["success" => true, "message" => translate('template_reset', $i18n)]));
}

$error = wallos_notification_template_error($title, $body);
if ($error !== null) {
    die(json_encode(["success" => false, "message" => translate('template_error', $i18n) . ': ' . $error]));
}

$db->exec('BEGIN IMMEDIATE');
$delete->execute();
$stmt = $db->prepare('INSERT INTO notification_templates (user_id, kind, channel, title, body) VALUES (:userId, :kind, :channel, :title, :body)');
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$stmt->bindValue(':kind', $kind, SQLITE3_TEXT);
$stmt->bindValue(':channel', $channel === '' ? null : $channel, $channel === '' ? SQLITE3_NULL : SQLITE3_TEXT);
$stmt->bindValue(':title', $title, SQLITE3_TEXT);
$stmt->bindValue(':body', $body, SQLITE3_TEXT);
$saved = $stmt->execute() !== false;
$db->exec($saved ? 'COMMIT' : 'ROLLBACK');

echo json_encode($saved
    ? ["success" => true, "message" => translate('notifications_settings_saved', $i18n)]
    : ["success" => false, "message" => translate('error_saving_notifications', $i18n)]);
