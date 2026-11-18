<?php
/*
  Web Push to every device the account registered. A push subscription
  belongs to whoever is signed into the account in that browser, not to a
  household member, so each payer's message goes to all of them.
*/

class WallosPushChannel extends WallosNotificationChannel
{
    const NAME = 'push';
    const LABEL = 'Push';
    const TABLE = 'push_notifications';

    private $devices = null;
    private $vapid = null;
    private $subject = null;

    public function isEnabled($kind)
    {
        // No point loading the VAPID keys for an account that enabled push
        // but has not registered a device yet.
        return !empty($this->settings['enabled']) && $this->devices() !== [];
    }

    private function devices()
    {
        if ($this->devices === null) {
            $this->devices = [];
            $stmt = $this->env->db->prepare('SELECT id, endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = :userId');
            $stmt->bindValue(':userId', $this->env->userId, SQLITE3_INTEGER);
            $result = $stmt->execute();
            while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
                $this->devices[] = $row;
            }
        }

        return $this->devices;
    }

    protected function send(array $message, array $context)
    {
        require_once __DIR__ . '/../../webpush_helper.php';
        require_once __DIR__ . '/../../instance_config.php';

        if ($this->vapid === null) {
            $this->vapid = webpush_get_vapid_keys($this->env->db);
            $adminSettings = wallos_get_admin_settings($this->env->db);
            $this->subject = !empty($adminSettings['from_email']) ? 'mailto:' . $adminSettings['from_email'] : 'mailto:wallos@wallosapp.com';
        }
        if ($this->vapid === false) {
            $this->env->log("Push notifications not sent: could not load this installation's VAPID keys.");
            return false;
        }

        $payload = json_encode(['title' => $message['title'], 'body' => $message['body']]);
        $sent = false;
        foreach ($this->devices() as $index => $device) {
            $pin = $this->env->ssrf($device['endpoint']);
            if (!$pin) {
                $this->env->log('SSRF attempt detected for a push subscription endpoint. Notification not sent.');
                continue;
            }
            $result = webpush_send($device, $payload, $this->vapid, $this->subject, 2419200, $pin);
            if ($result['prune']) {
                // The push service discarded this subscription (unsubscribed,
                // uninstalled, cleared site data); see webpush_send(). Not sent
                // to again in this run, even if removing the row fails.
                unset($this->devices[$index]);
                try {
                    $prune = $this->env->db->prepare('DELETE FROM push_subscriptions WHERE id = :id AND user_id = :userId');
                    $removed = $prune !== false;
                    if ($removed) {
                        $prune->bindValue(':id', $device['id'], SQLITE3_INTEGER);
                        $prune->bindValue(':userId', $this->env->userId, SQLITE3_INTEGER);
                        $removed = $prune->execute() !== false;
                    }
                } catch (\Throwable $e) {
                    $removed = false;
                }
                $this->env->log($removed ? 'Push subscription gone, removed.' : 'Push subscription gone; removing it failed.');
            } elseif ($result['success']) {
                $this->env->log('Push notification sent');
                $sent = true;
            } else {
                $this->env->log('Error sending Push notification: ' . ($result['error'] ?? 'unknown error'));
            }
        }

        return $sent;
    }
}
