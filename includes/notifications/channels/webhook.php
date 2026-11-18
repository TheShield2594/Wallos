<?php
/*
  The webhook sends one request per subscription, from the user's own JSON
  payload template ({{placeholder}} fields, see includes/webhook_helper.php),
  not the rendered message, so existing payloads keep working unchanged.
*/

class WallosWebhookChannel extends WallosNotificationChannel
{
    const NAME = 'webhook';
    const LABEL = 'Webhook';
    const TABLE = 'webhook_notifications';
    const ESCAPE = 'json';
    const USES_MESSAGE = false;

    public function isEnabled($kind)
    {
        if (empty($this->settings['enabled'])) {
            return false;
        }

        // Cancellation reminders go out only with a payload of their own.
        return $kind !== 'cancellation' || trim((string) ($this->settings['cancelation_payload'] ?? '')) !== '';
    }

    /**
     * The placeholder values for one subscription.
     */
    public static function placeholders($kind, array $subscription, $payer)
    {
        if ($kind === 'cancellation') {
            return [
                'subscription_name' => $subscription['name'] ?? '',
                'subscription_price' => $subscription['price'] ?? '',
                'subscription_currency' => $subscription['currency'] ?? '',
                'subscription_category' => $subscription['category'] ?? '',
                'subscription_payer' => $payer,
                'subscription_date' => $subscription['date'] ?? '',
                'subscription_cancel_by' => $subscription['cancel_by'] ?? '',
                'subscription_url' => $subscription['url'] ?? '',
                'subscription_notes' => $subscription['notes'] ?? '',
            ];
        }

        return [
            'days_until' => $subscription['days'] ?? '',
            'subscription_name' => $subscription['name'] ?? '',
            'subscription_price' => $subscription['formatted_price'] ?? '',
            'subscription_currency' => $subscription['currency'] ?? '',
            'subscription_category' => $subscription['category'] ?? '',
            'subscription_payer' => $payer,
            'subscription_date' => $subscription['date'] ?? '',
            'subscription_days_until_payment' => $subscription['days'] ?? '',
            'subscription_url' => $subscription['url'] ?? '',
            'subscription_notes' => $subscription['notes'] ?? '',
        ];
    }

    public function sendForPayer(array $message, array $context)
    {
        $kind = $context['kind'] ?? 'payment';
        $template = $kind === 'cancellation' ? ($this->settings['cancelation_payload'] ?? '') : ($this->settings['payload'] ?? '');
        $subscriptions = $context['subscriptions'] ?? [];
        if ($subscriptions === []) {
            return [];
        }

        $url = $this->settings['url'] ?? '';
        $pin = $this->guard($url);
        if (!$pin) {
            return [];
        }

        $delivered = [];
        foreach ($subscriptions as $index => $subscription) {
            if ($index > 0) {
                $this->env->pause();
            }
            $payload = webhookFillPayload($template, self::placeholders($kind, $subscription, $context['payer'] ?? ''));
            $response = $this->env->http([
                'method' => $this->settings['request_method'] ?? 'POST',
                'url' => $url,
                'headers' => webhookBuildHeaders($this->settings['headers'] ?? ''),
                'body' => $payload,
                'ssrf' => $pin,
                'ignore_ssl' => !empty($this->settings['ignore_ssl']),
            ]);
            if ($this->report($response, 'Webhook (subscription ' . (int) ($subscription['id'] ?? 0) . ')') && isset($subscription['key'])) {
                $delivered[] = $subscription['key'];
            }
        }

        return $delivered;
    }

    protected function send(array $message, array $context)
    {
        return false;
    }
}
