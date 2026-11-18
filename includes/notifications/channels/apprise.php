<?php
/*
  Apprise API (issue #38): one channel that reaches the 100+ services an
  Apprise API server (https://github.com/caronc/apprise-api) knows.

  mode 'key'   POST {url}/notify/{config_key}: the server holds the
               destination URLs under a saved configuration key.
  mode 'urls'  POST {url}/notify/ with the destination Apprise URLs in the
               request (one per line in the settings). Those URLs carry
               third-party secrets, so they are never logged.
*/

require_once __DIR__ . '/../../admin_roles.php';

class WallosAppriseChannel extends WallosNotificationChannel
{
    const NAME = 'apprise';
    const LABEL = 'Apprise';
    const TABLE = 'apprise_notifications';

    /**
     * The request for one message; also used by the test endpoint.
     *
     * @return array|null null when the settings are incomplete
     */
    public static function buildRequest(array $settings, $title, $body)
    {
        $base = trim((string) ($settings['url'] ?? ''));
        if ($base === '') {
            return null;
        }
        $payload = ['title' => (string) $title, 'body' => (string) $body, 'type' => 'info', 'format' => 'text'];
        $tags = trim((string) ($settings['tags'] ?? ''));
        if ($tags !== '') {
            $payload['tag'] = $tags;
        }

        if (($settings['mode'] ?? 'key') === 'urls') {
            $urls = self::parseUrls($settings['urls'] ?? '');
            if ($urls === []) {
                return null;
            }
            $payload['urls'] = implode(',', $urls);
            $url = self::routeUrl($base, '/notify/');
        } else {
            $key = trim((string) ($settings['config_key'] ?? ''));
            if ($key === '' || !preg_match('/^[A-Za-z0-9_-]{1,128}$/', $key)) {
                return null;
            }
            $url = self::routeUrl($base, '/notify/' . $key);
        }

        return [
            'method' => 'POST',
            'url' => $url,
            'headers' => ['Content-Type: application/json', 'Accept: application/json'],
            'body' => json_encode($payload),
            'ignore_ssl' => !empty($settings['ignore_ssl']),
        ];
    }

    /**
     * Appends an API route to the server URL's path, keeping a reverse-proxy
     * prefix (/apprise) and any query string.
     */
    public static function routeUrl($base, $route)
    {
        $parts = parse_url($base);
        if ($parts === false || empty($parts['host'])) {
            return rtrim($base, '/') . $route;
        }
        $url = ($parts['scheme'] ?? 'http') . '://';
        if (isset($parts['user'])) {
            $url .= $parts['user'] . (isset($parts['pass']) ? ':' . $parts['pass'] : '') . '@';
        }
        $url .= $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $url .= rtrim($parts['path'] ?? '', '/') . $route;
        if (isset($parts['query'])) {
            $url .= '?' . $parts['query'];
        }

        return $url;
    }

    /**
     * Whether an Apprise answer is a delivery. Apprise API answers 200 when it
     * sent the notification; 204 means the key had no configuration, which
     * sent nothing.
     */
    public static function delivered(array $response)
    {
        return $response['ok'] && (int) $response['code'] === 200;
    }

    /**
     * The destination hosts a stateless request would make the Apprise
     * server contact, that are private, loopback, link-local or reserved and
     * not on the admin's allowlist; empty when all are allowed.
     *
     * Apprise URLs are "scheme://...": for services addressed by host
     * (json://, xml://, form://, mailto://, ntfy://, gotify://, ...) the host
     * is the destination; for token-based services (tgram://bottoken/...)
     * the "host" is a token that resolves to nothing and passes.
     *
     * @param string[]      $urls
     * @param callable|null $resolve fn(string $host): string|null IP, for tests
     * @return string[]
     */
    public static function blockedDestinations(array $urls, SQLite3 $db, $userId, ?callable $resolve = null)
    {
        $configuration = wallos_get_effective_ssrf_allowlist($db);
        $allowlist = $configuration['allowlist'];
        $mayUseAllowlist = $configuration['allow_standard_users'] || wallos_is_admin($db, $userId);
        $blocked = [];
        foreach ($urls as $url) {
            $host = parse_url(preg_replace('/^[a-z0-9+.-]+:\/\//i', 'http://', $url), PHP_URL_HOST);
            if (!is_string($host) || $host === '') {
                continue;
            }
            $host = trim($host, '[]');
            if (filter_var($host, FILTER_VALIDATE_IP)) {
                $ip = $host;
            } else {
                $ip = $resolve !== null ? $resolve($host) : (gethostbyname($host) !== $host ? gethostbyname($host) : null);
            }
            if ($ip === null || !wallos_ip_is_private_or_reserved($ip)) {
                continue;
            }
            if ($mayUseAllowlist && (in_array($host, $allowlist, true) || in_array($ip, $allowlist, true))) {
                continue;
            }
            $blocked[] = $host;
        }

        return array_values(array_unique($blocked));
    }

    /**
     * One Apprise URL per line (commas also accepted), blanks dropped.
     *
     * @return string[]
     */
    public static function parseUrls($raw)
    {
        $urls = preg_split('/[\r\n,]+/', (string) $raw);

        return array_values(array_filter(array_map('trim', $urls), fn($url) => $url !== ''));
    }

    protected function send(array $message, array $context)
    {
        $request = self::buildRequest($this->settings, $message['title'], $message['body']);
        if ($request === null) {
            $this->env->log('Apprise notification skipped: the server URL or destination is missing.');
            return false;
        }
        $pin = $this->guard($request['url']);
        if (!$pin) {
            return false;
        }
        $request['ssrf'] = $pin;
        // Destinations the server would contact for us get the same checks
        // as our own requests (re-checked at send time: DNS can change).
        if (($this->settings['mode'] ?? 'key') === 'urls'
            && self::blockedDestinations(self::parseUrls($this->settings['urls'] ?? ''), $this->env->db, $this->env->userId, $this->env->destinationResolver) !== []) {
            $this->env->log('Apprise notification skipped: a destination failed SSRF validation.');
            return false;
        }

        $response = $this->env->http($request);
        $response['ok'] = self::delivered($response);
        // The answer can echo the stateless URLs back; never log the body.
        $response['error'] = $response['ok'] ? '' : ($response['code'] > 0 ? 'HTTP ' . $response['code'] : 'request failed');

        return $this->report($response);
    }
}
