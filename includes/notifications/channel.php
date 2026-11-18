<?php
/*
  Notification channels (issue #37).

  Every channel is a class in includes/notifications/channels/ that turns one
  rendered message (title + body) for one payer into a delivery. Both crons
  build the context, then dispatch.php loops over the account's enabled
  channels, so a channel added here reaches payment and cancellation
  reminders alike.

  WallosNotifyEnv carries what a channel needs from outside - the database,
  the account, HTTP, the SSRF guard, logging - so tests can swap HTTP and the
  SSRF check for fakes and look at the requests a channel would send.
*/

require_once __DIR__ . '/../ssrf_helper.php';

class WallosNotifyEnv
{
    /** @var SQLite3 */
    public $db;
    /** @var int */
    public $userId;
    /** @var callable|null fn(array $request): array */
    public $httpTransport;
    /** @var callable|null fn(string $url): array|false */
    public $ssrfCheck;
    /** @var callable|null fn(string $host, int $port): string|false, the approved IP */
    public $smtpCheck;
    /** @var callable|null fn(): object a PHPMailer-like mailer */
    public $mailerFactory;
    /** @var callable|null fn(string $line) */
    public $logger;
    /** @var callable|null fn(string $host): string|null, resolves Apprise destination hosts (tests) */
    public $destinationResolver;
    /** @var bool pause between per-subscription webhook calls */
    public $throttle = true;

    public function __construct(SQLite3 $db, $userId, array $overrides = [])
    {
        $this->db = $db;
        $this->userId = (int) $userId;
        foreach ($overrides as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }

    /**
     * Sends an HTTP request.
     *
     * @param array $request method, url, headers[], body, ssrf (pin from ssrf()), ignore_ssl
     * @return array ['ok' => bool 2xx, 'code' => int, 'body' => string|false, 'error' => string]
     */
    public function http(array $request)
    {
        if ($this->httpTransport !== null) {
            return call_user_func($this->httpTransport, $request);
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $request['url']);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $request['method'] ?? 'POST');
        if (array_key_exists('body', $request) && $request['body'] !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $request['body']);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $request['headers'] ?? []);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        if (!empty($request['ignore_ssl'])) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        }
        if (!empty($request['ssrf'])) {
            $pin = $request['ssrf'];
            curl_setopt($ch, CURLOPT_RESOLVE, ["{$pin['host']}:{$pin['port']}:{$pin['ip']}"]);
        }

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = $body === false ? curl_error($ch) : '';
        unset($ch);

        return [
            'ok' => $body !== false && $code >= 200 && $code < 300,
            'code' => $code,
            'body' => $body,
            'error' => $error !== '' ? $error : ($code >= 300 ? 'HTTP ' . $code : ''),
        ];
    }

    /**
     * The SSRF pin for a URL, or false when it must not be called. Checked
     * at send time, since a host can be re-pointed after it was saved.
     *
     * @return array|false
     */
    public function ssrf($url)
    {
        if ($this->ssrfCheck !== null) {
            return call_user_func($this->ssrfCheck, $url);
        }

        return is_url_safe_for_ssrf($url, $this->db, $this->userId);
    }

    /**
     * The IP an SMTP host was resolved to and approved for, or false when it
     * must not be used. The mailer must connect to this IP (see
     * wallos_pin_mailer_to_ip()) rather than resolving the host again.
     *
     * @return string|false
     */
    public function smtpValidatedIp($host, $port)
    {
        $ip = $this->smtpCheck !== null
            ? call_user_func($this->smtpCheck, $host, $port)
            : validate_smtp_host($host, (int) $port, $this->db);

        return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : false;
    }

    public function log($line)
    {
        if ($this->logger !== null) {
            call_user_func($this->logger, $line);
            return;
        }
        // Run from the admin page the log is HTML: subscription and account
        // names in it are data, not markup.
        echo php_sapi_name() === 'cli' ? $line . "\n" : htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . "<br />\n";
    }

    public function pause()
    {
        if ($this->throttle) {
            usleep(1000000);
        }
    }
}

abstract class WallosNotificationChannel
{
    /** channel id, as stored in notification_templates.channel */
    const NAME = '';
    /** label for logs */
    const LABEL = '';
    /** settings table */
    const TABLE = '';
    /** how {{ }} output is escaped in this channel's messages */
    const ESCAPE = 'none';
    /** whether the channel sends the rendered message (the webhook sends its own payload) */
    const USES_MESSAGE = true;

    /** @var array the channel's settings row */
    public $settings;
    /** @var WallosNotifyEnv */
    protected $env;

    public function __construct(array $settings, WallosNotifyEnv $env)
    {
        $this->settings = $settings;
        $this->env = $env;
    }

    /**
     * Whether this channel takes part in a run of the given kind.
     *
     * @param string $kind 'payment' or 'cancellation'
     */
    public function isEnabled($kind)
    {
        return !empty($this->settings['enabled']);
    }

    /**
     * Sends one payer's message. Returns the keys of the reminders it
     * delivered: all of them on success, none on failure. A channel that
     * sends per subscription (the webhook) overrides this.
     *
     * @param array $message ['title', 'body', 'custom_title']
     * @param array $context the payer's context; 'subscriptions' carry 'key'
     * @return string[]
     */
    public function sendForPayer(array $message, array $context)
    {
        if ($message['body'] === '') {
            return [];
        }
        if (!$this->send($message, $context)) {
            return [];
        }

        return array_values(array_filter(array_map(fn($entry) => $entry['key'] ?? null, $context['subscriptions'] ?? [])));
    }

    /**
     * Delivers one message.
     *
     * @return bool whether the service accepted it
     */
    abstract protected function send(array $message, array $context);

    /**
     * The body with a custom title on top, for services without a title
     * field; the default message (no custom title) is sent as before.
     */
    protected static function titledBody(array $message)
    {
        if (empty($message['custom_title']) || trim((string) $message['title']) === '') {
            return $message['body'];
        }

        return $message['title'] . "\n\n" . $message['body'];
    }

    /**
     * Splits text into parts of at most $limit characters, at line breaks
     * where possible, so a long digest is sent whole as several messages.
     *
     * @return string[]
     */
    protected static function splitText($text, $limit)
    {
        if (mb_strlen($text, 'UTF-8') <= $limit) {
            return [$text];
        }
        $parts = [];
        $current = '';
        foreach (preg_split('/(?<=\n)/', $text) as $line) {
            while (mb_strlen($line, 'UTF-8') > $limit) {
                if ($current !== '') {
                    $parts[] = $current;
                    $current = '';
                }
                $parts[] = mb_substr($line, 0, $limit, 'UTF-8');
                $line = mb_substr($line, $limit, null, 'UTF-8');
            }
            if (mb_strlen($current . $line, 'UTF-8') > $limit) {
                $parts[] = $current;
                $current = '';
            }
            $current .= $line;
        }
        if ($current !== '') {
            $parts[] = $current;
        }

        return $parts;
    }

    /**
     * Adds a query parameter to a URL that may already have a query.
     */
    protected static function withQuery($url, $name, $value)
    {
        return $url . (strpos($url, '?') === false ? '?' : '&') . $name . '=' . rawurlencode($value);
    }

    /**
     * Logs the outcome of an HTTP call and answers whether it was delivered.
     */
    protected function report(array $response, $what = null)
    {
        $what = $what ?? static::LABEL;
        if ($response['ok']) {
            $this->env->log($what . ' notification sent');
            return true;
        }
        $this->env->log('Error sending ' . $what . ' notification: ' . ($response['error'] !== '' ? $response['error'] : 'HTTP ' . $response['code']));

        return false;
    }

    /**
     * SSRF pin for a URL, logging a refusal.
     *
     * @return array|false
     */
    protected function guard($url)
    {
        $pin = $this->env->ssrf($url);
        if (!$pin) {
            $this->env->log(static::LABEL . ' notification skipped: URL failed SSRF validation.');
        }

        return $pin;
    }
}
