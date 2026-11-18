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
        $base = rtrim(trim((string) ($settings['url'] ?? '')), '/');
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
            $url = $base . '/notify/';
        } else {
            $key = trim((string) ($settings['config_key'] ?? ''));
            if ($key === '' || !preg_match('/^[A-Za-z0-9_-]{1,128}$/', $key)) {
                return null;
            }
            $url = $base . '/notify/' . $key;
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

        $response = $this->env->http($request);
        // The answer can echo the stateless URLs back; never log the body.
        $response['error'] = $response['ok'] ? '' : ($response['code'] > 0 ? 'HTTP ' . $response['code'] : 'request failed');

        return $this->report($response);
    }
}
