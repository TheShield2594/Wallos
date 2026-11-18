<?php
/*
  Notification messages (issue #37): the built-in default templates, the
  user's own templates, and the context a template is rendered with.

  The defaults reproduce the text the crons sent before templates existed,
  so nothing changes until a user edits a template.

  Context variables:
    kind                    'payment' or 'cancellation'
    channel                 the channel the message is rendered for
    user                    the account's username
    payer                   the household member the reminder is for ('' if none)
    subscriptions[]         name, price, formatted_price, currency,
                            currency_symbol, category, payer, date, days,
                            days_text, url, notes, cancel_by, deadline_note
    period_summary          "Amount for this pay period: ..." (payment only)
    include_period_summary  whether the summary belongs in this message
*/

require_once __DIR__ . '/../template_engine.php';

const WALLOS_NOTIFICATION_KINDS = ['payment', 'cancellation'];

/**
 * @return array ['title' => string, 'body' => string]
 */
function wallos_default_notification_template($kind)
{
    $greeting = '{% if payer and channel != "email" %}{{ payer }}, the following subscriptions are up for WHAT:' . "\n"
        . '{% else %}The following subscriptions are up for WHAT:' . "\n" . '{% endif %}';

    if ($kind === 'cancellation') {
        return [
            'title' => 'Tally Cancellation Notification',
            'body' => str_replace('WHAT', 'cancellation', $greeting)
                . '{% for s in subscriptions %}{{ s.name }} for {{ s.price }}{{ s.deadline_note }}' . "\n" . '{% endfor %}',
        ];
    }

    return [
        'title' => 'Tally Notification',
        'body' => '{% if subscriptions %}' . str_replace('WHAT', 'renewal', $greeting)
            . '{% for s in subscriptions %}{{ s.name }} for {{ s.formatted_price }} ({{ s.days_text }})' . "\n" . '{% endfor %}'
            . '{% if include_period_summary %}' . "\n" . '{{ period_summary }}' . "\n" . '{% endif %}'
            . '{% else %}{% if payer and channel != "email" %}{{ payer }}, {% endif %}{{ period_summary }}' . "\n" . '{% endif %}',
    ];
}

/**
 * The account's saved templates.
 *
 * @return array [kind][channel or ''] => ['title' => string, 'body' => string]
 */
function wallos_notification_templates_load(SQLite3 $db, $userId)
{
    $templates = [];
    $stmt = $db->prepare('SELECT kind, channel, title, body FROM notification_templates WHERE user_id = :userId');
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $templates[$row['kind']][(string) ($row['channel'] ?? '')] = ['title' => (string) $row['title'], 'body' => (string) $row['body']];
    }

    return $templates;
}

/**
 * The template a channel uses: its own override, else the account's
 * template for the kind, else the built-in default. A blank title or body
 * falls back field by field.
 *
 * @return array ['title', 'body', 'custom_title' => bool]
 */
function wallos_notification_template_for($kind, $channel, array $templates)
{
    $default = wallos_default_notification_template($kind);
    $chosen = $templates[$kind][$channel] ?? $templates[$kind][''] ?? null;
    $general = $templates[$kind][''] ?? null;

    $title = $default['title'];
    $customTitle = false;
    foreach ([$general, $chosen] as $candidate) {
        if ($candidate !== null && trim($candidate['title']) !== '') {
            $title = $candidate['title'];
            // The default title saved with an edited body is not a custom one.
            $customTitle = trim($candidate['title']) !== $default['title'];
        }
    }
    $body = $default['body'];
    foreach ([$general, $chosen] as $candidate) {
        if ($candidate !== null && trim($candidate['body']) !== '') {
            $body = $candidate['body'];
        }
    }

    return ['title' => $title, 'body' => $body, 'custom_title' => $customTitle];
}

/**
 * Renders the message one channel sends for one payer. A template that fails
 * to render (it was valid when saved, so this means a limit was hit) falls
 * back to the built-in default rather than sending nothing.
 *
 * @return array ['title', 'body', 'custom_title', 'error' => string|null]
 */
function wallos_render_notification_message($kind, $channel, array $context, array $templates, $escape = 'none')
{
    $template = wallos_notification_template_for($kind, $channel, $templates);
    $context['channel'] = $channel;
    $context['kind'] = $kind;
    $error = null;

    try {
        $title = trim(wallos_template_render($template['title'], $context, 'none'));
        $body = wallos_template_render($template['body'], $context, $escape);
    } catch (WallosTemplateException $exception) {
        $error = $exception->getMessage();
        $default = wallos_default_notification_template($kind);
        $title = $default['title'];
        $template['custom_title'] = false;
        try {
            $body = wallos_template_render($default['body'], $context, $escape);
        } catch (WallosTemplateException $tooLarge) {
            // Even the built-in message is over a limit: the caller splits
            // the digest (wallos_notification_messages()).
            $body = null;
        }
    }

    return ['title' => $title, 'body' => $body, 'custom_title' => $template['custom_title'], 'error' => $error];
}

/**
 * The messages one channel sends for one payer: normally one, or the digest
 * split into parts when it is too large to render as one message. Each part
 * carries the context (and so the reminder keys) it covers.
 *
 * @return array list of [message, context]
 */
function wallos_notification_messages($kind, $channel, array $context, array $templates, $escape = 'none')
{
    $message = wallos_render_notification_message($kind, $channel, $context, $templates, $escape);
    if ($message['body'] !== null) {
        return [[$message, $context]];
    }
    $subscriptions = $context['subscriptions'] ?? [];
    if (count($subscriptions) < 2) {
        return [];
    }
    $half = (int) ceil(count($subscriptions) / 2);
    $parts = [];
    foreach (array_chunk($subscriptions, $half) as $chunk) {
        foreach (wallos_notification_messages($kind, $channel, array_merge($context, ['subscriptions' => $chunk]), $templates, $escape) as $part) {
            $parts[] = $part;
        }
    }

    return $parts;
}

/**
 * "Today", "Tomorrow", "In 3 days", "2 days overdue".
 */
function wallos_notification_days_text($days)
{
    $days = (int) $days;
    if ($days === 0) {
        return 'Today';
    }
    if ($days === 1) {
        return 'Tomorrow';
    }
    if ($days < 0) {
        return -$days === 1 ? '1 day overdue' : -$days . ' days overdue';
    }

    return 'In ' . $days . ' days';
}

/**
 * A price with the currency's symbol in place of its code.
 */
function wallos_notification_format_price($price, $currencyCode, $currencySymbol)
{
    if (!class_exists('CurrencyFormatter')) {
        require_once __DIR__ . '/../currency_formatter.php';
    }
    $formatted = CurrencyFormatter::format($price, $currencyCode);
    if ($currencyCode !== '' && strpos($formatted, $currencyCode) !== false) {
        $formatted = str_replace($currencyCode, $currencySymbol . ' ', $formatted);
        $formatted = preg_replace('/\s+/', ' ', $formatted);
    }

    return $formatted;
}

/**
 * One subscription's entry in a message context.
 *
 * @param array $row        a subscriptions row
 * @param array $lookups    ['currencies' => id => row, 'categories' => id => row, 'household' => id => row]
 * @param int   $days       days until the payment (negative = overdue)
 * @param array $extra      merged in (cancel_by, deadline_note, key, price override, ...)
 * @return array
 */
function wallos_notification_entry(array $row, array $lookups, $days, array $extra = [])
{
    $currency = $lookups['currencies'][$row['currency_id']] ?? ['name' => '', 'symbol' => '', 'code' => ''];
    $symbol = (string) ($currency['symbol'] ?? '');

    return array_merge([
        'id' => (int) $row['id'],
        'name' => html_entity_decode((string) $row['name'], ENT_QUOTES, 'UTF-8'),
        'price' => $row['price'] . $symbol,
        'formatted_price' => wallos_notification_format_price($row['price'], (string) ($currency['code'] ?? ''), $symbol),
        'currency' => (string) ($currency['name'] ?? ''),
        'currency_symbol' => $symbol,
        'category' => (string) ($lookups['categories'][$row['category_id']]['name'] ?? ''),
        'payer' => (string) ($lookups['household'][$row['payer_user_id']]['name'] ?? ''),
        'date' => (string) $row['next_payment'],
        'days' => (int) $days,
        'days_text' => wallos_notification_days_text($days),
        'url' => (string) ($row['url'] ?? ''),
        'notes' => (string) ($row['notes'] ?? ''),
        'cancel_by' => '',
        'deadline_note' => '',
    ], $extra);
}

/**
 * The lookups wallos_notification_entry() reads, for one account.
 */
function wallos_notification_lookups(SQLite3 $db, $userId)
{
    $lookups = ['currencies' => [], 'categories' => [], 'household' => []];
    foreach (['currencies' => 'currencies', 'categories' => 'categories', 'household' => 'household'] as $key => $table) {
        $stmt = $db->prepare('SELECT * FROM ' . $table . ' WHERE user_id = :userId');
        $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
            $lookups[$key][$row['id']] = $row;
        }
    }

    return $lookups;
}

/**
 * Groups entries into one context per payer.
 *
 * @param array  $entriesByPayer payer id => entries
 * @param array  $household      id => household row
 * @param array  $owner          the user row (username, email)
 * @param string $kind
 * @param array  $extra          merged into every context (period summary)
 * @return array list of contexts
 */
function wallos_notification_contexts(array $entriesByPayer, array $household, array $owner, $kind, array $extra = [])
{
    $contexts = [];
    foreach ($entriesByPayer as $payerId => $entries) {
        $member = $household[$payerId] ?? [];
        $contexts[] = array_merge([
            'kind' => $kind,
            'user' => (string) ($owner['username'] ?? ''),
            'owner_email' => (string) ($owner['email'] ?? ''),
            'payer' => (string) ($member['name'] ?? ''),
            'payer_email' => (string) ($member['email'] ?? ''),
            'subscriptions' => array_values($entries),
            'period_summary' => '',
            'include_period_summary' => false,
        ], $extra);
    }

    return $contexts;
}

/**
 * Validates a template for saving; the error, or null.
 */
function wallos_notification_template_error($title, $body)
{
    foreach ([$title, $body] as $template) {
        $error = wallos_template_error($template);
        if ($error !== null) {
            return $error;
        }
    }

    return null;
}
