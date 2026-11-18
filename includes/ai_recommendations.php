<?php
/*
  The AI recommendations prompt (issue #42), shared by the weekly/monthly cron
  (endpoints/cronjobs/generaterecommendations.php) and the on-demand endpoint
  (endpoints/ai/generate_recommendations.php).

  Each subscription is sent as name, price, frequency, category and payer.
  When the account allows it (ai_settings.include_descriptions, on by
  default), two hints about what the service is are added, only when present:

    url_host     the website's domain ("realdebrid.com"), never the full URL
    description  the subscription's own "What is this?" text (200 characters)

  Notes are never sent: they often hold account numbers and other private
  data.
*/

const WALLOS_SUBSCRIPTION_DESCRIPTION_MAX_LENGTH = 200;

/**
 * A description as stored: one line, trimmed, at most 200 characters, ''
 * when empty.
 *
 * @param mixed $value
 */
function wallos_normalize_subscription_description($value): string
{
    if (!is_string($value)) {
        return '';
    }
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

    return mb_substr($value, 0, WALLOS_SUBSCRIPTION_DESCRIPTION_MAX_LENGTH);
}

/**
 * The domain of a subscription's URL, without "www.", or '' when the URL
 * has no usable host. Only the host leaves the instance: no path, query or
 * credentials.
 *
 * @param mixed $url
 */
function wallos_ai_url_host($url): string
{
    if (!is_string($url)) {
        return '';
    }
    $url = trim(htmlspecialchars_decode($url));
    if ($url === '') {
        return '';
    }
    if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
        $url = 'https://' . $url;
    }
    $host = parse_url($url, PHP_URL_HOST);
    if (!is_string($host)) {
        return '';
    }
    $host = strtolower(rtrim($host, '.'));
    if (str_starts_with($host, 'www.')) {
        $host = substr($host, 4);
    }
    // A public-looking hostname only: no IP addresses, no single labels
    // ("localhost", "nas") that would say nothing and leak the network.
    if (filter_var($host, FILTER_VALIDATE_IP) !== false
        || !preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,62}$/', $host)) {
        return '';
    }

    return $host;
}

function wallos_ai_describe_frequency($cycle, $frequency): string
{
    if ((int) $cycle === 5) {
        return 'One-time purchase (lifetime)';
    }
    $unit = match ((int) $cycle) {
        1 => 'day',
        2 => 'week',
        3 => 'month',
        4 => 'year',
        default => 'unit'
    };

    return (int) $frequency <= 1 ? "Every $unit" : "Every " . (int) $frequency . " {$unit}s";
}

/**
 * The subscriptions as sent to the model.
 *
 * @param array[] $subscriptions        active subscription rows
 * @param array   $currencies           id => row (code)
 * @param array   $categories           id => row (name)
 * @param array   $members              id => row (name)
 * @param bool    $includeDescriptions  add url_host and description when present
 * @return array[]
 */
function wallos_ai_build_payload(array $subscriptions, array $currencies, array $categories, array $members, bool $includeDescriptions): array
{
    $payload = [];
    foreach ($subscriptions as $row) {
        if (!empty($row['inactive'])) {
            continue;
        }
        $price = round((float) $row['price'], 2);
        $currencyCode = $currencies[$row['currency_id']]['code'] ?? '';
        $item = [
            'name' => htmlspecialchars_decode((string) $row['name'], ENT_QUOTES),
            'price' => $currencyCode !== '' ? "$price $currencyCode" : "$price",
            'frequency' => wallos_ai_describe_frequency($row['cycle'], $row['frequency']),
            'category' => $categories[$row['category_id']]['name'] ?? 'Uncategorized',
            'payer' => $members[$row['payer_user_id']]['name'] ?? 'Unknown',
        ];

        if ($includeDescriptions) {
            $host = wallos_ai_url_host($row['url'] ?? '');
            if ($host !== '') {
                $item['url_host'] = $host;
            }
            $description = wallos_normalize_subscription_description($row['description'] ?? '');
            if ($description !== '') {
                $item['description'] = $description;
            }
        }

        $payload[] = $item;
    }

    return $payload;
}

/**
 * The full prompt: instructions, then the subscriptions as JSON.
 */
function wallos_ai_recommendations_prompt(array $payload, string $languageName, bool $includeDescriptions): string
{
    $fields = "- Name of the service\n"
        . "- Price (in original currency)\n"
        . "- Payment frequency (e.g., every month, every year, etc.) — entries marked \"One-time purchase (lifetime)\" are perpetual licenses already paid for, not recurring costs\n"
        . "- Category\n"
        . "- Payer (which household member pays for it)";
    $descriptionRule = '';
    if ($includeDescriptions) {
        $fields .= "\n- Optionally, url_host: the service's website domain"
            . "\n- Optionally, description: the user's own explanation of what the service is";
        $descriptionRule = "\n- If a description is provided, trust it over your own assumptions about the service."
            . " Use url_host to tell apart services with similar or ambiguous names.";
    }

    $prompt = <<<PROMPT
You are a helpful assistant designed to help users save money on digital subscriptions.

The user has shared a list of their active subscriptions across household members. For each subscription, you are given:
{$fields}

Analyze the data and give 3 to 7 smart and specific recommendations to reduce subscription costs. If possible, include estimated savings for each suggestion.

Follow these guidelines:
- Do NOT suggest switching to family or group plans unless two or more different household members are paying for the same or similar service.
- Recognize known feature overlaps, such as:
• YouTube Premium includes YouTube Music.
• Amazon Prime includes Prime Video.
• Google One, iCloud+, and Proton all offer cloud storage.
• Real Debrid, All Debrid, and Premiumize offer similar download capabilities.
- Suggest rotating or cancelling subscriptions that serve similar purposes (e.g. multiple streaming or IPTV services).
- Recommend switching from monthly to yearly plans only if it provides clear savings and the user is likely to keep the service long-term.
- Suggest looking for promo or new customer deals if a service appears overpriced.
- Only recommend cancelling rarely used services if they do not provide unique value.
- Do NOT suggest cancelling or changing one-time purchases (lifetime licenses) — they are already paid for and have no ongoing cost.{$descriptionRule}

Return the result as a JSON array. Each item in the array should have:
- "title": a short summary of the suggestion
- "description": a longer explanation with reasoning
- "savings": a rough estimate like "10 EUR/month" or "60 EUR/year" (if possible)

If possible, all text should be in the user's language: {$languageName}. Otherwise, use English.

Do not include any other text, just the JSON output. Absolutely no additional comments or explanations.

Here is the user's data:
PROMPT;

    return $prompt . "\n\n" . json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
