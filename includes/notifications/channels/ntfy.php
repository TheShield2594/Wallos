<?php

class WallosNtfyChannel extends WallosNotificationChannel
{
    /**
     * host + "/" + topic, whether or not the topic starts with a slash (the
     * test endpoint posts to the same URL).
     */
    public static function topicUrl($host, $topic)
    {
        return rtrim((string) $host, '/') . '/' . ltrim((string) $topic, '/');
    }

    const NAME = 'ntfy';
    const LABEL = 'Ntfy';
    const TABLE = 'ntfy_notifications';

    protected function send(array $message, array $context)
    {
        $host = rtrim((string) ($this->settings['host'] ?? ''), '/');
        $pin = $this->guard($host);
        if (!$pin) {
            return false;
        }

        $headers = array_merge(['Content-Type: text/plain; charset=utf-8'], webhookParseHeaders($this->settings['headers'] ?? ''));
        $url = self::topicUrl($host, $this->settings['topic'] ?? '');
        if (!empty($message['custom_title'])) {
            // A query parameter rather than a header: headers are Latin-1.
            $url = self::withQuery($url, 'title', str_replace(["\r", "\n"], ' ', $message['title']));
        }

        return $this->report($this->env->http([
            'method' => 'POST',
            'url' => $url,
            'headers' => $headers,
            'body' => $message['body'],
            'ssrf' => $pin,
            'ignore_ssl' => !empty($this->settings['ignore_ssl']),
        ]));
    }
}
