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

// The saved templates with the draft on top, so a channel draft inherits
// blank fields from the saved account-wide template exactly as delivery does.
$templates = wallos_notification_templates_load($db, $userId);
$templates[$kind][$channel] = ['title' => $title, 'body' => $body];
$context = wallos_notification_preview_context($db, $userId, $kind);
$message = wallos_render_notification_message($kind, $channel === '' ? 'discord' : $channel, $context, $templates);

if ($message['error'] !== null || $message['body'] === null) {
    // A draft that hits a limit with the preview data: say so, rather than
    // showing the built-in text as if it were the draft.
    die(json_encode(["success" => false, "message" => translate('template_error', $i18n) . ': ' . ($message['error'] ?? 'The message is too long.')]));
}

echo json_encode([
    "success" => true,
    "title" => $message['title'],
    "body" => $message['body'],
]);
