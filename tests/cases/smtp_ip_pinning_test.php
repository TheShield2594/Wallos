<?php
/*
  SMTP DNS-rebinding guard: validate_smtp_host() returns the IP it approved,
  and every mail path connects PHPMailer to that IP while still verifying the
  TLS certificate against the configured hostname (ssl.peer_name).
*/

require_once WALLOS_ROOT . '/tests/notification_fixtures.php';
require_once WALLOS_ROOT . '/includes/ssrf_helper.php';
require_once WALLOS_ROOT . '/libs/PHPMailer/PHPMailer.php';
require_once WALLOS_ROOT . '/libs/PHPMailer/SMTP.php';
require_once WALLOS_ROOT . '/libs/PHPMailer/Exception.php';

/**
 * A PHPMailer whose SMTP layer records what it was asked to connect to and
 * then refuses, so nothing leaves the test process.
 */
function smtp_pinning_recording_mailer(array &$connects)
{
    $smtp = new class extends PHPMailer\PHPMailer\SMTP {
        public $connects;
        public function connect($host, $port = null, $timeout = 30, $options = [])
        {
            $this->connects[] = ['host' => $host, 'port' => $port, 'options' => $options];
            return false;
        }
    };
    $smtp->connects = &$connects;

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->setSMTPInstance($smtp);

    return $mail;
}

wallos_test('validate_smtp_host returns the approved IP, or false', function () {
    $db = wallos_test_open_database();
    $previous = getenv('SSRF_ALLOWLIST');
    putenv('SSRF_ALLOWLIST');

    try {
        assert_same('93.184.216.34', validate_smtp_host('93.184.216.34', 587, $db), 'a public IP is returned as is');
        assert_same(false, validate_smtp_host('127.0.0.1', 587, $db), 'loopback is refused without an allowlist entry');
        assert_same(false, validate_smtp_host('169.254.169.254', 587, $db), 'the metadata address is refused');
        assert_same(false, validate_smtp_host('', 587, $db), 'an empty host is refused');

        putenv('SSRF_ALLOWLIST=10.0.0.5:2525');
        assert_same('10.0.0.5', validate_smtp_host('10.0.0.5', 2525, $db), 'an allowlisted ip:port returns its IP');
        assert_same(false, validate_smtp_host('10.0.0.5', 587, $db), 'the same IP on another port is still refused');
    } finally {
        putenv($previous === false ? 'SSRF_ALLOWLIST' : 'SSRF_ALLOWLIST=' . $previous);
    }
    $db->close();
});

wallos_test('wallos_pin_mailer_to_ip connects to the IP and verifies the hostname', function () {
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->SMTPOptions = ['ssl' => ['verify_depth' => 5], 'socket' => ['bindto' => '0:0']];
    wallos_pin_mailer_to_ip($mail, 'smtp.example.com', '203.0.113.7');
    assert_same('203.0.113.7', $mail->Host, 'Host is the approved IP');
    assert_same('smtp.example.com', $mail->SMTPOptions['ssl']['peer_name'], 'the certificate is checked against the hostname');
    assert_same(5, $mail->SMTPOptions['ssl']['verify_depth'], 'existing ssl options are kept');
    assert_same(['bindto' => '0:0'], $mail->SMTPOptions['socket'], 'other context options are kept');
    assert_true($mail->SMTPAutoTLS, 'opportunistic STARTTLS stays on for a remote host');

    $fqdn = new PHPMailer\PHPMailer\PHPMailer(true);
    wallos_pin_mailer_to_ip($fqdn, 'smtp.example.com.', '203.0.113.7');
    assert_same('smtp.example.com', $fqdn->SMTPOptions['ssl']['peer_name'], 'a terminal DNS dot is dropped from peer_name');

    $v6 = new PHPMailer\PHPMailer\PHPMailer(true);
    wallos_pin_mailer_to_ip($v6, '[2001:db8::1]', '2001:db8::1');
    assert_same('[2001:db8::1]', $v6->Host, 'an IPv6 address is bracketed for PHPMailer');
    assert_same('2001:db8::1', $v6->SMTPOptions['ssl']['peer_name'], 'peer_name has no brackets');
    assert_true(PHPMailer\PHPMailer\PHPMailer::isValidHost($v6->Host), 'PHPMailer accepts the pinned IPv6 host');

    $local = new PHPMailer\PHPMailer\PHPMailer(true);
    wallos_pin_mailer_to_ip($local, 'localhost', '127.0.0.1');
    assert_same(false, $local->SMTPAutoTLS, 'localhost keeps PHPMailer\'s no-auto-STARTTLS behaviour');

    // Through PHPMailer itself: the socket layer gets the IP and the peer_name.
    $connects = [];
    $mail = smtp_pinning_recording_mailer($connects);
    $mail->isSMTP();
    $mail->SMTPSecure = 'ssl';
    $mail->Port = 465;
    wallos_pin_mailer_to_ip($mail, 'smtp.example.com', '203.0.113.7');
    try {
        $mail->smtpConnect();
    } catch (PHPMailer\PHPMailer\Exception $e) {
    }
    assert_same(1, count($connects), 'one connection attempt');
    assert_same('ssl://203.0.113.7', $connects[0]['host'], 'the socket opens to the IP, not the hostname');
    assert_same(465, $connects[0]['port'], 'on the configured port');
    assert_same('smtp.example.com', $connects[0]['options']['ssl']['peer_name'], 'the stream context verifies the hostname');
});

wallos_test('the email channel connects to the IP it validated', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    $requests = [];
    $log = [];
    $connects = [];
    $mailers = 0;
    $env = notification_test_env($db, 1, $requests, null, $log);
    $env->mailerFactory = function () use (&$connects, &$mailers) {
        $mailers++;
        return smtp_pinning_recording_mailer($connects);
    };
    $checked = [];
    $env->smtpCheck = function ($host, $port) use (&$checked) {
        $checked[] = [$host, $port];
        return $host === 'blocked.example' ? false : '203.0.113.25';
    };

    $settings = ['enabled' => 1, 'smtp_address' => 'smtp.example.com', 'smtp_port' => 587, 'encryption' => 'tls',
        'smtp_username' => 'u', 'smtp_password' => 'p', 'from_email' => '', 'other_emails' => ''];
    $context = ['payer' => 'Ann', 'payer_email' => 'ann@example.com', 'subscriptions' => [['name' => 'A', 'key' => 'payment:1']]];
    $message = ['title' => 'T', 'body' => 'B', 'custom_title' => false];

    $channel = new WallosEmailChannel($settings, $env);
    assert_same([], $channel->sendForPayer($message, $context), 'the refused connection is not a delivery');
    assert_same([['smtp.example.com', 587]], $checked, 'the host is validated at send time');
    assert_same(1, count($connects), 'one connection attempt');
    assert_same('203.0.113.25', $connects[0]['host'], 'the mailer connects to the validated IP');
    assert_same(587, $connects[0]['port'], 'on the configured port');
    assert_same('smtp.example.com', $connects[0]['options']['ssl']['peer_name'], 'TLS is verified against the hostname');

    $blocked = new WallosEmailChannel(array_merge($settings, ['smtp_address' => 'blocked.example']), $env);
    assert_same([], $blocked->sendForPayer($message, $context), 'a refused host is not sent to');
    assert_same(1, $mailers, 'no mailer is even built for a refused host');
    assert_contains('SSRF attempt detected for SMTP host. Email notifications not sent.', implode("\n", $log), 'the refusal is logged');

    $env->smtpCheck = fn() => true;
    $legacy = new WallosEmailChannel($settings, $env);
    assert_same([], $legacy->sendForPayer($message, $context), 'a check that approves without an IP is treated as a refusal');
    assert_same(1, $mailers, 'and nothing is connected to');
    $db->close();
});

wallos_test('every mail endpoint validates the SMTP host and pins the mailer to its IP', function () {
    foreach ([
        'endpoints/notifications/testemailnotifications.php',
        'endpoints/cronjobs/sendverificationemails.php',
        'endpoints/cronjobs/sendresetpasswordemails.php',
        'includes/notifications/channels/email.php',
    ] as $file) {
        $source = file_get_contents(WALLOS_ROOT . '/' . $file);
        assert_contains('wallos_pin_mailer_to_ip($mail, ', $source, $file . ' pins the mailer to the approved IP');
        assert_true(preg_match('/->Host\s*=/', $source) === 0, $file . ' never hands PHPMailer the hostname to resolve');
    }
    foreach (['endpoints/cronjobs/sendverificationemails.php', 'endpoints/cronjobs/sendresetpasswordemails.php', 'endpoints/notifications/testemailnotifications.php'] as $file) {
        assert_contains('$smtpIp = validate_smtp_host(', file_get_contents(WALLOS_ROOT . '/' . $file), $file . ' validates the host when it sends');
    }
});
