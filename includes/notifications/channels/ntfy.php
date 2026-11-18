<?php

class WallosNtfyChannel extends WallosNotificationChannel
{
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
        $url = $host . '/' . ($this->settings['topic'] ?? '');
        if (!empty($message['custom_title'])) {
            // A query parameter rather than a header: headers are Latin-1.
            $url .= '?title=' . rawurlencode(str_replace(["\r", "\n"], ' ', $message['title']));
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
