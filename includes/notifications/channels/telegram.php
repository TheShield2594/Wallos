<?php

class WallosTelegramChannel extends WallosNotificationChannel
{
    const NAME = 'telegram';
    const LABEL = 'Telegram';
    const TABLE = 'telegram_notifications';

    protected function send(array $message, array $context)
    {
        // Messages are limited to 4096 characters: a long digest is split.
        foreach (self::splitText(self::titledBody($message), 4096) as $part) {
            $response = $this->env->http([
                'method' => 'POST',
                'url' => 'https://api.telegram.org/bot' . ($this->settings['bot_token'] ?? '') . '/sendMessage',
                'headers' => ['Content-Type: application/json'],
                'body' => json_encode(['chat_id' => $this->settings['chat_id'] ?? '', 'text' => $part]),
            ]);

            // The Bot API reports failures in a 200 answer's "ok" field.
            $response['ok'] = wallos_notification_http_delivered($response['code'], $response['body'], true);
            if (!$response['ok'] && $response['error'] === '') {
                $response['error'] = 'Telegram rejected the message';
            }
            if (!$this->report($response)) {
                return false;
            }
        }

        return true;
    }
}
