<?php
/*
  Money formatting.

  Each currency can set how many decimals it shows (currencies.decimals,
  migration 000070; NULL = automatic). getsettings.php registers the user's
  currencies with CurrencyFormatter::useCurrencies(), so every
  CurrencyFormatter::format($amount, $code) call follows them: a BTC row
  shows 0.0008 while totals in the main currency keep its own decimals.

  Automatic: ICU's digits for ISO currencies (USD 2, JPY 0); codes ICU does
  not know (BTC, ETH) show 2 to 8 digits, as many as the amount needs.
*/

const WALLOS_CURRENCY_MAX_DECIMALS = 8;

final class CurrencyFormatter
{
    private static $formatters = [];
    private static $decimalsByCode = [];
    private static $knownCodes = [];

    private static function locale()
    {
        if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            $locale = Locale::acceptFromHttp($_SERVER['HTTP_ACCEPT_LANGUAGE']);
            if (is_string($locale) && $locale !== '') {
                return $locale;
            }
        }

        return 'en_US';
    }

    private static function formatter($minDigits, $maxDigits)
    {
        $key = $minDigits === null ? 'default' : $minDigits . '-' . $maxDigits;
        if (!isset(self::$formatters[$key])) {
            $formatter = new NumberFormatter(self::locale(), NumberFormatter::CURRENCY);
            if ($minDigits !== null) {
                $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $minDigits);
                $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $maxDigits);
            }
            self::$formatters[$key] = $formatter;
        }

        return self::$formatters[$key];
    }

    /**
     * Remembers each currency's decimals setting by code.
     *
     * @param iterable $currencies rows with code and decimals
     */
    public static function useCurrencies($currencies)
    {
        self::$decimalsByCode = [];
        foreach ($currencies as $currency) {
            if (!isset($currency['code'])) {
                continue;
            }
            $decimals = self::sanitizeDecimals($currency['decimals'] ?? null);
            if ($decimals !== null) {
                self::$decimalsByCode[strtoupper($currency['code'])] = $decimals;
            }
        }
    }

    /**
     * @param mixed $decimals
     * @return int|null 0-8, or null for automatic
     */
    public static function sanitizeDecimals($decimals)
    {
        if ($decimals === null || $decimals === '' || $decimals === 'auto') {
            return null;
        }
        if (!is_numeric($decimals)) {
            return null;
        }
        $decimals = (int) $decimals;

        return $decimals >= 0 && $decimals <= WALLOS_CURRENCY_MAX_DECIMALS ? $decimals : null;
    }

    /**
     * Whether ICU knows the currency code (ISO 4217).
     */
    public static function isKnownCode($code)
    {
        $code = strtoupper((string) $code);
        if (!array_key_exists($code, self::$knownCodes)) {
            $known = false;
            try {
                $bundle = ResourceBundle::create('en', 'ICUDATA-curr');
                $names = $bundle ? $bundle->get('Currencies') : null;
                $known = $names !== null && $names->get($code) !== null;
            } catch (Throwable $e) {
                $known = true;
            }
            self::$knownCodes[$code] = $known;
        }

        return self::$knownCodes[$code];
    }

    /**
     * The minimum and maximum fraction digits a currency shows.
     *
     * @param string   $code
     * @param int|null $decimals the currency's setting; looked up by code when null
     * @return array{0: int|null, 1: int|null} [null, null] for ICU's own digits
     */
    public static function fractionDigits($code, $decimals = null)
    {
        $decimals = self::sanitizeDecimals($decimals ?? (self::$decimalsByCode[strtoupper((string) $code)] ?? null));
        if ($decimals !== null) {
            return [$decimals, $decimals];
        }
        if (!self::isKnownCode($code)) {
            return [2, WALLOS_CURRENCY_MAX_DECIMALS];
        }

        return [null, null];
    }

    /**
     * How many decimals a price input accepts for this currency.
     *
     * @return int
     */
    public static function inputDecimals($code, $decimals = null)
    {
        [, $max] = self::fractionDigits($code, $decimals);
        if ($max !== null) {
            return $max;
        }
        $formatter = new NumberFormatter('en@currency=' . strtoupper((string) $code), NumberFormatter::CURRENCY);

        return (int) $formatter->getAttribute(NumberFormatter::MAX_FRACTION_DIGITS);
    }

    /**
     * @param float       $amount
     * @param string      $currency ISO code (or a custom one such as BTC)
     * @param int|null    $decimals overrides the registered setting
     * @return string
     */
    public static function format($amount, $currency, $decimals = null)
    {
        [$min, $max] = self::fractionDigits($currency, $decimals);

        return self::formatter($min, $max)->formatCurrency((float) $amount, $currency);
    }
}

if (!function_exists('formatPrice')) {
    /**
     * A formatted amount with the currency's own symbol in place of its code.
     *
     * @param float  $price
     * @param string $currencyCode
     * @param array  $currencies rows with code, symbol and decimals
     * @return string
     */
    function formatPrice($price, $currencyCode, $currencies)
    {
        $symbol = $currencyCode;
        $decimals = null;
        foreach ($currencies as $currency) {
            if (($currency['code'] ?? null) === $currencyCode) {
                if (($currency['symbol'] ?? '') != "") {
                    $symbol = $currency['symbol'];
                }
                $decimals = $currency['decimals'] ?? null;
                break;
            }
        }

        $formattedPrice = CurrencyFormatter::format($price, $currencyCode, $decimals);
        if (strstr($formattedPrice, $currencyCode)) {
            $formattedPrice = str_replace($currencyCode, $symbol, $formattedPrice);
        }

        return $formattedPrice;
    }
}

/**
 * An amount without symbol for exports (calendar descriptions): the
 * currency's decimals, trailing zeros trimmed down to its minimum.
 *
 * @return string
 */
function wallos_plain_price($price, $code, $decimals = null)
{
    [$min, $max] = CurrencyFormatter::fractionDigits($code, $decimals);
    if ($max === null) {
        $min = $max = CurrencyFormatter::inputDecimals($code, $decimals);
    }
    $formatted = number_format((float) $price, $max);
    if ($max > $min && strpos($formatted, '.') !== false) {
        [$whole, $fraction] = explode('.', $formatted, 2);
        $fraction = rtrim($fraction, '0');
        $fraction = str_pad($fraction, $min, '0');
        $formatted = $fraction === '' ? $whole : $whole . '.' . $fraction;
    }

    return $formatted;
}

/**
 * Rounds a stored price to the precision its currency allows.
 *
 * @return float
 */
function wallos_round_price($price, $code, $decimals = null)
{
    return round((float) $price, CurrencyFormatter::inputDecimals($code, $decimals));
}
