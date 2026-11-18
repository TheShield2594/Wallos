<?php
/*
  Renders a template, saved or not, with the account's own subscriptions, for
  the live preview in Settings -> Notifications (issue #37).

  POST JSON: { kind, channel, title, body }
*/

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/notifications/dispatch.php';
require_once '../../includes/notifications/preview.php';

$data = json_decode(file_get_contents("php://input"), true);
$kind = $data['kind'] ?? '';
$channel = (string) ($data['channel'] ?? '');
if (!in_array($kind, WALLOS_NOTIFICATION_KINDS, true)
    || ($channel !== '' && !array_key_exists($channel, wallos_notification_channel_classes()))) {
    die(json_encode(["success" => false, "message" => translate('error', $i18n)]));
}

$title = (string) ($data['title'] ?? '');
$body = (string) ($data['body'] ?? '');
$error = wallos_notification_template_error($title, $body);
if ($error !== null) {
    die(json_encode(["success" => false, "message" => translate('template_error', $i18n) . ': ' . $error]));
}

$templates = [$kind => [$channel => ['title' => $title, 'body' => $body]]];
$context = wallos_notification_preview_context($db, $userId, $kind);
$message = wallos_render_notification_message($kind, $channel === '' ? 'discord' : $channel, $context, $templates);

echo json_encode([
    "success" => true,
    "title" => $message['title'],
    "body" => $message['body'],
    "error" => $message['error'],
]);
