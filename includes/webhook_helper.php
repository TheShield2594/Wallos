<?php

/**
 * Escapes a value for safe interpolation into a JSON string field via plain
 * str_replace() templating (the webhook payload template uses
 * "{{placeholder}}" substitution, not real JSON building).
 *
 * Notes can now be multi-line Markdown containing quotes and backslashes, which
 * would otherwise break the payload's JSON structure. json_encode() quotes and
 * escapes the value correctly; the template already supplies the surrounding
 * quotes, so they have to come off again.
 *
 * The two characters json_encode() added, and only those. trim($encoded, '"')
 * strips *every* leading and trailing quote character, so a value ending in a
 * quotation mark lost the closing quote of its own \" escape and left a bare
 * backslash at the end of the payload's string - which stops being JSON. A note
 * reading He said "hi" is enough; it came out as He said \"hi\ and every
 * webhook for that subscription was sent as a body the receiver cannot parse.
 *
 * @param string $value
 * @return string
 */
function webhookJsonEscape($value)
{
    $encoded = json_encode((string) $value);

    // json_encode() answers false only for input it cannot represent, which a
    // string this function has already cast cannot be - but an empty value is
    // the safe answer rather than the literal "false".
    return $encoded === false ? '' : substr($encoded, 1, -1);
}

/**
 * Fills a webhook payload template's "{{placeholder}}" fields.
 *
 * Every value is JSON-escaped, not just notes: a subscription name, category
 * or URL containing a quote or a backslash otherwise breaks the payload's JSON
 * just as badly.
 *
 * @param string $template
 * @param array  $values   placeholder name (without braces) => value
 * @return string
 */
function webhookFillPayload($template, array $values)
{
    $replacements = [];
    foreach ($values as $key => $value) {
        $replacements['{{' . $key . '}}'] = webhookJsonEscape($value ?? '');
    }

    return strtr((string) $template, $replacements);
}

/**
 * Turns the custom headers a user saved into the list CURLOPT_HTTPHEADER takes.
 *
 * The field has been read as a JSON list by one cron and as one header per line
 * by the other, so accept all three shapes it can hold: a JSON list of
 * "Name: value" strings, a JSON object of name => value, or plain lines.
 *
 * @param string|null $raw
 * @return array
 */
function webhookParseHeaders($raw)
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return [];
    }

    $headers = [];
    $decoded = json_decode($raw, true);

    if (is_array($decoded)) {
        foreach ($decoded as $key => $value) {
            if (is_array($value)) {
                continue;
            }
            $headers[] = is_int($key) ? (string) $value : $key . ': ' . $value;
        }
    } else {
        $headers = preg_split("/\r\n|\n|\r/", $raw);
    }

    return array_values(array_filter(array_map('trim', $headers), function ($header) {
        return $header !== '';
    }));
}

/**
 * The request headers for a webhook call: the user's own, plus
 * Content-Type: application/json unless they already chose a content type.
 *
 * Without it curl sends the body as application/x-www-form-urlencoded, and
 * receivers such as n8n parse the whole JSON document as a single form key.
 *
 * @param string|null $raw
 * @return array
 */
function webhookBuildHeaders($raw)
{
    $headers = webhookParseHeaders($raw);

    foreach ($headers as $header) {
        if (stripos($header, 'content-type:') === 0) {
            return $headers;
        }
    }

    $headers[] = 'Content-Type: application/json';

    return $headers;
}

?>
