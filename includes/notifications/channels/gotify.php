<?php

class WallosGotifyChannel extends WallosNotificationChannel
{
    const NAME = 'gotify';
    const LABEL = 'Gotify';
    const TABLE = 'gotify_notifications';

    protected function send(array $message, array $context)
    {
        $url = $this->settings['url'] ?? '';
        $pin = $this->guard($url);
        if (!$pin) {
            return false;
        }

        $data = ['message' => $message['body'], 'priority' => 5];
        if (!empty($message['custom_title'])) {
            $data['title'] = $message['title'];
        }

        return $this->report($this->env->http([
            'method' => 'POST',
            'url' => rtrim($url, '/') . '/message?token=' . rawurlencode((string) ($this->settings['token'] ?? '')),
            'headers' => ['Content-Type: application/json'],
            'body' => json_encode($data),
            'ssrf' => $pin,
            'ignore_ssl' => !empty($this->settings['ignore_ssl']),
        ]));
    }
}
