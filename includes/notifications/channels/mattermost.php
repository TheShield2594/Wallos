<?php

class WallosMattermostChannel extends WallosNotificationChannel
{
    const NAME = 'mattermost';
    const LABEL = 'Mattermost';
    const TABLE = 'mattermost_notifications';

    protected function send(array $message, array $context)
    {
        $url = $this->settings['webhook_url'] ?? '';
        $pin = $this->guard($url);
        if (!$pin) {
            return false;
        }

        $data = ['text' => self::titledBody($message)];
        if (!empty($this->settings['bot_username'])) {
            $data['username'] = $this->settings['bot_username'];
        }
        if (!empty($this->settings['bot_icon_emoji'])) {
            $data['icon_emoji'] = $this->settings['bot_icon_emoji'];
        }

        // A Mattermost incoming webhook answers a plain "ok", not JSON, so
        // success is the HTTP status.
        return $this->report($this->env->http([
            'method' => 'POST',
            'url' => $url,
            'headers' => ['Content-Type: application/json'],
            'body' => json_encode($data),
            'ssrf' => $pin,
        ]));
    }
}
