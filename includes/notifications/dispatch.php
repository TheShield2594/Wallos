<?php
/*
  The shared dispatcher (issue #37): both reminder crons build one context per
  payer and hand it here, and every enabled channel gets its attempt.

  A channel that throws is logged and the next one still runs. The answer is
  the set of reminder keys at least one channel delivered; the crons mark only
  those as sent, so an undelivered reminder is tried again on the next run.
*/

require_once __DIR__ . '/channel.php';
require_once __DIR__ . '/messages.php';
require_once __DIR__ . '/../webhook_helper.php';
require_once __DIR__ . '/../cancellation_deadline.php';

foreach (glob(__DIR__ . '/channels/*.php') as $wallosChannelFile) {
    require_once $wallosChannelFile;
}
unset($wallosChannelFile);

/**
 * Channel id => class, in the order they are tried.
 *
 * @return string[]
 */
function wallos_notification_channel_classes()
{
    return [
        'email' => 'WallosEmailChannel',
        'discord' => 'WallosDiscordChannel',
        'gotify' => 'WallosGotifyChannel',
        'telegram' => 'WallosTelegramChannel',
        'pushplus' => 'WallosPushPlusChannel',
        'mattermost' => 'WallosMattermostChannel',
        'pushover' => 'WallosPushoverChannel',
        'ntfy' => 'WallosNtfyChannel',
        'webhook' => 'WallosWebhookChannel',
        'serverchan' => 'WallosServerchanChannel',
        'apprise' => 'WallosAppriseChannel',
        'push' => 'WallosPushChannel',
    ];
}

/**
 * The account's channels that take part in a run of this kind.
 *
 * @param string $kind 'payment' or 'cancellation'
 * @return WallosNotificationChannel[]
 */
function wallos_load_notification_channels(WallosNotifyEnv $env, $kind)
{
    $channels = [];
    foreach (wallos_notification_channel_classes() as $class) {
        $exists = $env->db->querySingle("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = '" . $class::TABLE . "'");
        if (!$exists) {
            continue;
        }
        $stmt = $env->db->prepare('SELECT * FROM ' . $class::TABLE . ' WHERE user_id = :userId LIMIT 1');
        $stmt->bindValue(':userId', $env->userId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
        if (!$row) {
            continue;
        }
        $channel = new $class($row, $env);
        if ($channel->isEnabled($kind)) {
            $channels[] = $channel;
        }
    }

    return $channels;
}

/**
 * Sends every payer's message through every channel.
 *
 * @param WallosNotificationChannel[] $channels
 * @param string                      $kind
 * @param array                       $contexts  one per payer (wallos_notification_contexts())
 * @param array                       $templates wallos_notification_templates_load()
 * @return array key => true for every reminder at least one channel delivered
 */
function wallos_dispatch_notifications(WallosNotifyEnv $env, array $channels, $kind, array $contexts, array $templates)
{
    $delivered = [];
    foreach ($channels as $channel) {
        try {
            foreach ($contexts as $context) {
                $message = wallos_render_notification_message($kind, $channel::NAME, $context, $templates, $channel::ESCAPE);
                if ($message['error'] !== null) {
                    $env->log('Template error (' . $channel::NAME . '), the default message was used: ' . $message['error']);
                }
                if (trim($message['body']) === '') {
                    continue;
                }
                foreach ($channel->sendForPayer($message, $context) as $key) {
                    $delivered[$key] = true;
                }
            }
        } catch (\Throwable $e) {
            // One channel failing must not stop the channels after it, or
            // every account after this one.
            $env->log('Error sending ' . $channel::LABEL . ' notifications: ' . $e->getMessage());
        }
    }

    return $delivered;
}
