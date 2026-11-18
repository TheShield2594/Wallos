<?php

class WallosDiscordChannel extends WallosNotificationChannel
{
    const NAME = 'discord';
    const LABEL = 'Discord';
    const TABLE = 'discord_notifications';

    protected function send(array $message, array $context)
    {
        $url = $this->settings['webhook_url'] ?? '';
        $pin = $this->guard($url);
        if (!$pin) {
            return false;
        }

        $fields = ['content' => $message['body']];
        if (!empty($this->settings['bot_username'])) {
            $fields['username'] = $this->settings['bot_username'];
        }
        if (!empty($this->settings['bot_avatar_url'])) {
            $fields['avatar_url'] = $this->settings['bot_avatar_url'];
        }

        return $this->report($this->env->http([
            'method' => 'POST',
            'url' => $url,
            'headers' => ['Content-Type: application/json'],
            'body' => json_encode($fields),
            'ssrf' => $pin,
        ]));
    }
}
