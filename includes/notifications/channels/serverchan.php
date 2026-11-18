<?php

class WallosServerchanChannel extends WallosNotificationChannel
{
    const NAME = 'serverchan';
    const LABEL = 'Serverchan';
    const TABLE = 'serverchan_notifications';

    protected function send(array $message, array $context)
    {
        $sendkey = (string) ($this->settings['sendkey'] ?? '');
        if (strpos($sendkey, 'sctp') === 0) {
            preg_match('/^sctp(\d+)t/', $sendkey, $matches);
            $url = 'https://' . ($matches[1] ?? '') . '.push.ft07.com/send/' . $sendkey . '.send';
        } else {
            $url = 'https://sctapi.ftqq.com/' . $sendkey . '.send';
        }

        return $this->report($this->env->http([
            'method' => 'POST',
            'url' => $url,
            'headers' => ['Content-Type: application/x-www-form-urlencoded'],
            'body' => http_build_query(['text' => $message['title'], 'desp' => $message['body']]),
        ]));
    }
}
