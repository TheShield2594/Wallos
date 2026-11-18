<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/ssrf_helper.php';
require_once '../../includes/notifications/dispatch.php';
require_once '../../includes/notifications/apprise_settings.php';

$data = json_decode(file_get_contents("php://input"), true);
$settings = wallos_apprise_settings_from_input(is_array($data) ? $data : [], true);
if ($settings['error'] !== null) {
    die(json_encode(["success" => false, "message" => translate($settings['error'], $i18n)]));
}

$request = WallosAppriseChannel::buildRequest($settings['fields'], translate('wallos_notification', $i18n), translate('test_notification', $i18n));
if ($request === null) {
    die(json_encode(["success" => false, "message" => translate('fill_mandatory_fields', $i18n)]));
}
$request['ssrf'] = validate_webhook_url_for_ssrf($request['url'], $db, $i18n, $userId);

$env = new WallosNotifyEnv($db, $userId, ['logger' => function () {
}]);
$response = $env->http($request);

// The answer can echo stateless destination URLs, which carry secrets, so
// only the status goes back.
echo json_encode($response['ok']
    ? ["success" => true, "message" => translate('notification_sent_successfuly', $i18n)]
    : ["success" => false, "message" => translate('notification_failed', $i18n) . ($response['code'] > 0 ? ' (HTTP ' . $response['code'] . ')' : '')]);
