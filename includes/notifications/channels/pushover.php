<?php

class WallosPushoverChannel extends WallosNotificationChannel
{
    const NAME = 'pushover';
    const LABEL = 'Pushover';
    const TABLE = 'pushover_notifications';

    protected function send(array $message, array $context)
    {
        $fields = [
            'token' => $this->settings['token'] ?? '',
            'user' => $this->settings['user_key'] ?? '',
            'message' => $message['body'],
        ];
        if (!empty($message['custom_title'])) {
            // Pushover allows 250 characters.
            $fields['title'] = mb_substr($message['title'], 0, 250, 'UTF-8');
        }

        return $this->report($this->env->http([
            'method' => 'POST',
            'url' => 'https://api.pushover.net/1/messages.json',
            'headers' => ['Content-Type: application/x-www-form-urlencoded'],
            'body' => http_build_query($fields),
        ]));
    }
}
