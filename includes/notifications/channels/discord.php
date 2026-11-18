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

        // wait=true: Discord answers only once the message is stored, so a
        // success is a delivery. Content is limited to 2000 characters, so a
        // long digest goes out as several messages.
        $target = preg_match('/[?&]wait=/', $url) ? $url : self::withQuery($url, 'wait', 'true');
        foreach (self::splitText(self::titledBody($message), 2000) as $part) {
            $fields = ['content' => $part];
            if (!empty($this->settings['bot_username'])) {
                $fields['username'] = $this->settings['bot_username'];
            }
            if (!empty($this->settings['bot_avatar_url'])) {
                $fields['avatar_url'] = $this->settings['bot_avatar_url'];
            }
            $sent = $this->report($this->env->http([
                'method' => 'POST',
                'url' => $target,
                'headers' => ['Content-Type: application/json'],
                'body' => json_encode($fields),
                'ssrf' => $pin,
            ]));
            if (!$sent) {
                return false;
            }
        }

        return true;
    }
}
