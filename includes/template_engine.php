<?php
/*
  A small, safe template language for notification messages (issue #37).

  Syntax (a Jinja-like subset, no code execution):

    {{ name }}                    a variable; dotted paths reach into arrays
    {{ s.price | upper }}         filters: upper, lower, capitalize, trim,
                                  default("x"), json, html, url, raw
    {% for s in subscriptions %}  loops over a list; loop.index, loop.first,
    {% endfor %}                  loop.last inside
    {% if a %} {% elif b %} {% else %} {% endif %}
                                  conditions: a value's truthiness, `not`,
                                  comparisons (== != < > <= >=) against a
                                  variable or a "string"/number literal,
                                  combined with `and` / `or`
    {# a comment #}

  Output of {{ }} is escaped for the channel the message goes to (`json`,
  `html` or `none`) unless the expression ends in `| raw`. Unknown variables
  render as an empty string.

  Limits keep a template from running away: its length, the number of loop
  iterations, the nesting depth and the size of the output are all capped,
  and going over one of them throws WallosTemplateException.
*/

class WallosTemplateException extends RuntimeException
{
}

const WALLOS_TEMPLATE_MAX_LENGTH = 10000;
const WALLOS_TEMPLATE_MAX_OUTPUT = 65536;
const WALLOS_TEMPLATE_MAX_ITERATIONS = 2000;
const WALLOS_TEMPLATE_MAX_DEPTH = 16;
const WALLOS_TEMPLATE_FILTERS = ['upper', 'lower', 'capitalize', 'trim', 'default', 'json', 'html', 'url', 'raw'];

/**
 * Parses a template into a tree. Throws WallosTemplateException on a syntax
 * error, so the settings page can reject a broken template when it is saved.
 *
 * @param string $template
 * @return array
 */
function wallos_template_parse($template)
{
    $template = (string) $template;
    if (strlen($template) > WALLOS_TEMPLATE_MAX_LENGTH) {
        throw new WallosTemplateException('The template is longer than ' . WALLOS_TEMPLATE_MAX_LENGTH . ' characters.');
    }

    $tokens = preg_split('/(\{\{.*?\}\}|\{%.*?%\}|\{#.*?#\})/s', $template, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
    $position = 0;
    $nodes = wallos_template_parse_nodes($tokens, $position, [], 0);
    if ($position < count($tokens)) {
        throw new WallosTemplateException('Unexpected ' . trim($tokens[$position]) . '.');
    }

    return $nodes;
}

/**
 * @param array    $tokens
 * @param int      $position advanced past what was consumed
 * @param string[] $until    tag names that end this block
 * @param int      $depth
 * @return array
 */
function wallos_template_parse_nodes(array $tokens, &$position, array $until, $depth)
{
    if ($depth > WALLOS_TEMPLATE_MAX_DEPTH) {
        throw new WallosTemplateException('Blocks are nested too deeply.');
    }

    $nodes = [];
    $count = count($tokens);
    while ($position < $count) {
        $token = $tokens[$position];

        if (strncmp($token, '{#', 2) === 0 && substr($token, -2) === '#}') {
            $position++;
            continue;
        }

        if (strncmp($token, '{{', 2) === 0 && substr($token, -2) === '}}') {
            $nodes[] = ['type' => 'output', 'expr' => wallos_template_parse_output(trim(substr($token, 2, -2)))];
            $position++;
            continue;
        }

        if (strncmp($token, '{%', 2) === 0 && substr($token, -2) === '%}') {
            $tag = trim(substr($token, 2, -2));
            $name = strtolower((string) strtok($tag, " \t\n"));
            if (in_array($name, $until, true)) {
                return $nodes;
            }
            $position++;

            if ($name === 'for') {
                if (!preg_match('/^for\s+([A-Za-z_]\w*)\s+in\s+([A-Za-z_][\w.]*)$/', $tag, $match)) {
                    throw new WallosTemplateException('Invalid loop: {% ' . $tag . ' %}.');
                }
                $body = wallos_template_parse_nodes($tokens, $position, ['endfor'], $depth + 1);
                wallos_template_expect($tokens, $position, 'endfor');
                $nodes[] = ['type' => 'for', 'var' => $match[1], 'path' => explode('.', $match[2]), 'body' => $body];
                continue;
            }

            if ($name === 'if') {
                $branches = [];
                $condition = wallos_template_parse_condition(trim(substr($tag, 2)));
                while (true) {
                    $body = wallos_template_parse_nodes($tokens, $position, ['elif', 'else', 'endif'], $depth + 1);
                    $branches[] = ['condition' => $condition, 'body' => $body];
                    if ($position >= $count) {
                        throw new WallosTemplateException('Missing {% endif %}.');
                    }
                    $closing = trim(substr($tokens[$position], 2, -2));
                    $closingName = strtolower((string) strtok($closing, " \t\n"));
                    $position++;
                    if ($closingName === 'elif') {
                        $condition = wallos_template_parse_condition(trim(substr($closing, 4)));
                        continue;
                    }
                    if ($closingName === 'else') {
                        $body = wallos_template_parse_nodes($tokens, $position, ['endif'], $depth + 1);
                        wallos_template_expect($tokens, $position, 'endif');
                        $branches[] = ['condition' => null, 'body' => $body];
                    }
                    break;
                }
                $nodes[] = ['type' => 'if', 'branches' => $branches];
                continue;
            }

            throw new WallosTemplateException('Unknown tag: {% ' . $tag . ' %}.');
        }

        $nodes[] = ['type' => 'text', 'text' => $token];
        $position++;
    }

    if ($until !== []) {
        throw new WallosTemplateException('Missing {% ' . $until[count($until) - 1] . ' %}.');
    }

    return $nodes;
}

function wallos_template_expect(array $tokens, &$position, $name)
{
    if ($position >= count($tokens)) {
        throw new WallosTemplateException('Missing {% ' . $name . ' %}.');
    }
    $position++;
}

/**
 * "path | filter | filter("arg")"
 */
function wallos_template_parse_output($expression)
{
    $parts = wallos_template_split_filters($expression);
    $value = wallos_template_parse_operand(array_shift($parts));
    $filters = [];
    foreach ($parts as $part) {
        if (!preg_match('/^([a-z_]+)\s*(?:\(\s*(.*?)\s*\))?$/s', trim($part), $match)) {
            throw new WallosTemplateException('Invalid filter: ' . trim($part) . '.');
        }
        if (!in_array($match[1], WALLOS_TEMPLATE_FILTERS, true)) {
            throw new WallosTemplateException('Unknown filter: ' . $match[1] . '.');
        }
        $argument = isset($match[2]) && $match[2] !== '' ? wallos_template_parse_operand($match[2]) : null;
        $filters[] = ['name' => $match[1], 'arg' => $argument];
    }

    return ['value' => $value, 'filters' => $filters];
}

/**
 * Splits on "|" outside string literals.
 */
function wallos_template_split_filters($expression)
{
    $parts = [];
    $current = '';
    $quote = null;
    $length = strlen($expression);
    for ($i = 0; $i < $length; $i++) {
        $char = $expression[$i];
        if ($quote !== null) {
            $current .= $char;
            if ($char === $quote) {
                $quote = null;
            }
            continue;
        }
        if ($char === '"' || $char === "'") {
            $quote = $char;
        }
        if ($char === '|') {
            $parts[] = $current;
            $current = '';
            continue;
        }
        $current .= $char;
    }
    $parts[] = $current;

    return $parts;
}

/**
 * A variable path, a quoted string or a number.
 */
function wallos_template_parse_operand($operand)
{
    $operand = trim((string) $operand);
    if ($operand === '') {
        throw new WallosTemplateException('Empty expression.');
    }
    if (preg_match('/^"([^"]*)"$|^\'([^\']*)\'$/', $operand, $match)) {
        return ['literal' => $match[1] !== '' ? $match[1] : ($match[2] ?? '')];
    }
    if (is_numeric($operand)) {
        return ['literal' => $operand + 0];
    }
    if (in_array(strtolower($operand), ['true', 'false'], true)) {
        return ['literal' => strtolower($operand) === 'true'];
    }
    if (!preg_match('/^[A-Za-z_]\w*(\.\w+)*$/', $operand)) {
        throw new WallosTemplateException('Invalid expression: ' . $operand . '.');
    }

    return ['path' => explode('.', $operand)];
}

/**
 * "a or b and not c == 'x'": `or` of `and`s of (optionally negated)
 * comparisons or values.
 */
function wallos_template_parse_condition($condition)
{
    if ($condition === '') {
        throw new WallosTemplateException('Empty condition.');
    }
    $any = [];
    foreach (preg_split('/\s+or\s+/i', $condition) as $orPart) {
        $all = [];
        foreach (preg_split('/\s+and\s+/i', $orPart) as $andPart) {
            $andPart = trim($andPart);
            $negate = false;
            if (preg_match('/^not\s+(.*)$/is', $andPart, $match)) {
                $negate = true;
                $andPart = trim($match[1]);
            }
            if (preg_match('/^(.+?)\s*(==|!=|<=|>=|<|>)\s*(.+)$/s', $andPart, $match)) {
                $all[] = [
                    'negate' => $negate,
                    'left' => wallos_template_parse_operand($match[1]),
                    'op' => $match[2],
                    'right' => wallos_template_parse_operand($match[3]),
                ];
            } else {
                $all[] = ['negate' => $negate, 'left' => wallos_template_parse_operand($andPart), 'op' => null, 'right' => null];
            }
        }
        $any[] = $all;
    }

    return $any;
}

/**
 * Renders a template.
 *
 * @param string $template
 * @param array  $context
 * @param string $escape 'none', 'json' or 'html': how {{ }} output is escaped
 * @return string
 */
function wallos_template_render($template, array $context, $escape = 'none')
{
    $state = ['iterations' => 0, 'length' => 0, 'escape' => $escape];

    return wallos_template_render_nodes(wallos_template_parse($template), [$context], $state);
}

function wallos_template_render_nodes(array $nodes, array $scopes, array &$state)
{
    $output = '';
    foreach ($nodes as $node) {
        switch ($node['type']) {
            case 'text':
                $piece = $node['text'];
                break;
            case 'output':
                $piece = wallos_template_output($node['expr'], $scopes, $state['escape']);
                break;
            case 'for':
                $items = wallos_template_lookup($node['path'], $scopes);
                $piece = '';
                if (is_array($items)) {
                    $items = array_values($items);
                    $total = count($items);
                    foreach ($items as $index => $item) {
                        if (++$state['iterations'] > WALLOS_TEMPLATE_MAX_ITERATIONS) {
                            throw new WallosTemplateException('Too many loop iterations.');
                        }
                        $loop = ['index' => $index + 1, 'index0' => $index, 'first' => $index === 0, 'last' => $index === $total - 1, 'length' => $total];
                        $piece .= wallos_template_render_nodes($node['body'], array_merge($scopes, [[$node['var'] => $item, 'loop' => $loop]]), $state);
                    }
                }
                break;
            case 'if':
                $piece = '';
                foreach ($node['branches'] as $branch) {
                    if ($branch['condition'] === null || wallos_template_condition($branch['condition'], $scopes)) {
                        $piece = wallos_template_render_nodes($branch['body'], $scopes, $state);
                        break;
                    }
                }
                break;
            default:
                $piece = '';
        }

        $state['length'] += strlen($piece);
        if ($state['length'] > WALLOS_TEMPLATE_MAX_OUTPUT * 4) {
            throw new WallosTemplateException('The message is too long.');
        }
        $output .= $piece;
    }

    if (strlen($output) > WALLOS_TEMPLATE_MAX_OUTPUT) {
        throw new WallosTemplateException('The message is too long.');
    }

    return $output;
}

/**
 * Looks a dotted path up, innermost scope first.
 */
function wallos_template_lookup(array $path, array $scopes)
{
    for ($i = count($scopes) - 1; $i >= 0; $i--) {
        if (is_array($scopes[$i]) && array_key_exists($path[0], $scopes[$i])) {
            $value = $scopes[$i][$path[0]];
            foreach (array_slice($path, 1) as $key) {
                if (!is_array($value) || !array_key_exists($key, $value)) {
                    return null;
                }
                $value = $value[$key];
            }

            return $value;
        }
    }

    return null;
}

function wallos_template_value(array $operand, array $scopes)
{
    return array_key_exists('literal', $operand) ? $operand['literal'] : wallos_template_lookup($operand['path'], $scopes);
}

function wallos_template_truthy($value)
{
    if (is_array($value)) {
        return $value !== [];
    }
    if (is_string($value)) {
        return $value !== '' && $value !== '0';
    }

    return (bool) $value;
}

function wallos_template_condition(array $any, array $scopes)
{
    foreach ($any as $all) {
        $matches = true;
        foreach ($all as $term) {
            $left = wallos_template_value($term['left'], $scopes);
            if ($term['op'] === null) {
                $result = wallos_template_truthy($left);
            } else {
                $right = wallos_template_value($term['right'], $scopes);
                if (is_numeric($left) && is_numeric($right)) {
                    $left += 0;
                    $right += 0;
                } else {
                    $left = is_array($left) ? count($left) : (string) $left;
                    $right = is_array($right) ? count($right) : (string) $right;
                }
                switch ($term['op']) {
                    case '==': $result = $left == $right; break;
                    case '!=': $result = $left != $right; break;
                    case '<': $result = $left < $right; break;
                    case '>': $result = $left > $right; break;
                    case '<=': $result = $left <= $right; break;
                    default: $result = $left >= $right;
                }
            }
            if ($term['negate']) {
                $result = !$result;
            }
            if (!$result) {
                $matches = false;
                break;
            }
        }
        if ($matches) {
            return true;
        }
    }

    return false;
}

function wallos_template_output(array $expr, array $scopes, $escape)
{
    $value = wallos_template_value($expr['value'], $scopes);
    $raw = false;
    $escaped = false;
    foreach ($expr['filters'] as $filter) {
        $string = is_array($value) ? '' : (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
        switch ($filter['name']) {
            case 'upper': $value = mb_strtoupper($string, 'UTF-8'); break;
            case 'lower': $value = mb_strtolower($string, 'UTF-8'); break;
            case 'capitalize': $value = mb_strtoupper(mb_substr($string, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($string, 1, null, 'UTF-8'); break;
            case 'trim': $value = trim($string); break;
            case 'default':
                $value = wallos_template_truthy($value) ? $value : ($filter['arg'] !== null ? wallos_template_value($filter['arg'], $scopes) : '');
                break;
            case 'json': $value = wallos_template_escape($string, 'json'); $escaped = true; break;
            case 'html': $value = wallos_template_escape($string, 'html'); $escaped = true; break;
            case 'url': $value = rawurlencode($string); $escaped = true; break;
            case 'raw': $raw = true; break;
        }
    }

    $string = is_array($value) ? '' : (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);

    return $raw || $escaped ? $string : wallos_template_escape($string, $escape);
}

/**
 * @param string $value
 * @param string $mode 'json' (inside a JSON string), 'html' or 'none'
 */
function wallos_template_escape($value, $mode)
{
    switch ($mode) {
        case 'json':
            $encoded = json_encode((string) $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return $encoded === false ? '' : substr($encoded, 1, -1);
        case 'html':
            return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        default:
            return (string) $value;
    }
}

/**
 * Checks a template compiles; the error message, or null.
 */
function wallos_template_error($template)
{
    try {
        wallos_template_parse($template);
    } catch (WallosTemplateException $error) {
        return $error->getMessage();
    }

    return null;
}
