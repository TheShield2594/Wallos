<?php
/*
  Date formatting for every page (issue #32).

  settings.date_format (migration 000069) picks the order of day, month and
  year. 'auto' keeps the pattern the UI language suggests, which is what the
  pages did before.

  Styles
    short  day and month; the year is added when it is not the current year
    long   always day, month and year
    month  month and year, for chart labels and month headings

  getsettings.php stores the user's choice in $GLOBALS['wallos_date_format'],
  so callers only pass the date (and the UI language, which names months).
  scripts/common.js carries the same table as tallyFormatDate().
*/

const WALLOS_DATE_FORMATS = [
    'auto' => null,
    'dmy' => ['short' => 'dd/MM', 'long' => 'dd/MM/yyyy', 'month' => 'MM/yy'],
    'mdy' => ['short' => 'MM/dd', 'long' => 'MM/dd/yyyy', 'month' => 'MM/yy'],
    'iso' => ['short' => 'yyyy-MM-dd', 'long' => 'yyyy-MM-dd', 'month' => 'yyyy-MM'],
    'd_mon_y' => ['short' => 'd MMM', 'long' => 'd MMM yyyy', 'month' => 'MMM yy'],
    'mon_d_y' => ['short' => 'MMM d', 'long' => 'MMM d, yyyy', 'month' => 'MMM yy'],
];

/**
 * @param mixed $format
 * @return string a key of WALLOS_DATE_FORMATS, 'auto' when unknown
 */
function wallos_sanitize_date_format($format)
{
    return is_string($format) && array_key_exists($format, WALLOS_DATE_FORMATS) ? $format : 'auto';
}

/**
 * The ICU pattern for a format, style and language.
 *
 * @param string $format    a key of WALLOS_DATE_FORMATS
 * @param string $style     'short', 'long' or 'month'
 * @param bool   $withYear  only for 'short': whether the year is shown
 * @param string $lang
 * @return string
 */
function wallos_date_pattern($format, $style, $withYear, $lang)
{
    $format = wallos_sanitize_date_format($format);
    if ($style === 'short' && $withYear) {
        $style = 'long';
    }
    if (!in_array($style, ['short', 'long', 'month'], true)) {
        $style = 'short';
    }

    if ($format !== 'auto') {
        return WALLOS_DATE_FORMATS[$format][$style];
    }

    // The language's own order for these fields ("12. Dez." in German).
    $skeleton = ['short' => 'MMMd', 'long' => 'yMMMd', 'month' => 'MMMyy'][$style];
    $fallback = ['short' => 'MMM d', 'long' => 'MMM d, yyyy', 'month' => 'MMM yy'][$style];
    if (class_exists('IntlDatePatternGenerator')) {
        try {
            $pattern = (new IntlDatePatternGenerator($lang))->getBestPattern($skeleton);
            if (is_string($pattern) && $pattern !== '') {
                return $pattern;
            }
        } catch (Throwable $e) {
            // Unknown locale: use the English pattern below.
        }
    }

    return $fallback;
}

/**
 * Formats a date for display.
 *
 * @param mixed       $date   'Y-m-d' (or longer) string, timestamp or DateTimeInterface
 * @param string      $style  'short', 'long' or 'month'
 * @param string|null $lang   UI language; the page's $lang when null
 * @param string|null $format a key of WALLOS_DATE_FORMATS; the user's setting when null
 * @return string
 */
function tally_format_date($date, $style = 'short', $lang = null, $format = null)
{
    if ($date instanceof DateTimeInterface) {
        $value = new DateTime($date->format('Y-m-d'));
    } elseif (is_int($date)) {
        $value = (new DateTime())->setTimestamp($date);
    } else {
        try {
            $value = new DateTime((string) $date);
        } catch (Throwable $e) {
            return (string) $date;
        }
    }

    $lang = $lang ?? ($GLOBALS['lang'] ?? 'en');
    $format = $format ?? ($GLOBALS['wallos_date_format'] ?? 'auto');
    $withYear = $value->format('Y') !== date('Y');
    $pattern = wallos_date_pattern($format, $style, $withYear, $lang);

    foreach ([$lang, 'en'] as $locale) {
        try {
            $formatter = new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::NONE, null, null, $pattern);
            $formatted = $formatter->format($value);
            if ($formatted !== false) {
                return $formatted;
            }
        } catch (Throwable $e) {
            // Try English next.
        }
    }

    return $value->format('Y-m-d');
}

/**
 * "Dec 20 – Jan 19": both ends carry the year when the range crosses one, or
 * when it is not the current year.
 *
 * @return string
 */
function tally_format_date_range(DateTimeInterface $start, DateTimeInterface $end, $lang = null, $format = null)
{
    $crossesYear = $start->format('Y') !== $end->format('Y');
    $style = $crossesYear ? 'long' : 'short';

    return tally_format_date($start, $style, $lang, $format) . ' - ' . tally_format_date($end, $style, $lang, $format);
}

if (!function_exists('formatDate')) {
    /**
     * The short date the lists and dashboard show (see tally_format_date()).
     */
    function formatDate($date, $lang = 'en')
    {
        return tally_format_date($date, 'short', $lang);
    }
}
