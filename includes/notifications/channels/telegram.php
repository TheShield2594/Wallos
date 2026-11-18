<?php

class WallosTelegramChannel extends WallosNotificationChannel
{
    const NAME = 'telegram';
    const LABEL = 'Telegram';
    const TABLE = 'telegram_notifications';

    protected function send(array $message, array $context)
    {
        $response = $this->env->http([
            'method' => 'POST',
            'url' => 'https://api.telegram.org/bot' . ($this->settings['bot_token'] ?? '') . '/sendMessage',
            'headers' => ['Content-Type: application/json'],
            'body' => json_encode(['chat_id' => $this->settings['chat_id'] ?? '', 'text' => $message['body']]),
        ]);

        // The Bot API reports failures in a 200 answer's "ok" field.
        $response['ok'] = wallos_notification_http_delivered($response['code'], $response['body'], true);
        if (!$response['ok'] && $response['error'] === '') {
            $response['error'] = 'Telegram rejected the message';
        }

        return $this->report($response);
    }
}
