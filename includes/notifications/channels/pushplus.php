<?php

class WallosPushPlusChannel extends WallosNotificationChannel
{
    const NAME = 'pushplus';
    const LABEL = 'PushPlus';
    const TABLE = 'pushplus_notifications';

    protected function send(array $message, array $context)
    {
        $title = $message['title'];
        if (empty($message['custom_title']) && ($context['kind'] ?? '') === 'payment') {
            $title = '订阅续期提醒 - Tally';
        }

        $response = $this->env->http([
            'method' => 'POST',
            'url' => 'https://www.pushplus.plus/send',
            'headers' => ['Content-Type: application/json'],
            'body' => json_encode([
                'token' => $this->settings['token'] ?? '',
                'title' => $title,
                'content' => $message['body'],
                'template' => 'json',
            ]),
        ]);

        if ($response['ok']) {
            $decoded = json_decode((string) $response['body'], true);
            if (!isset($decoded['code']) || (int) $decoded['code'] !== 200) {
                $response['ok'] = false;
                $response['error'] = 'PushPlus API error: ' . ($decoded['msg'] ?? 'Unknown error');
            }
        }

        return $this->report($response);
    }
}
