<?php
/*
  Per-currency decimal places (includes/currency_formatter.php, issue #33).
*/

require_once WALLOS_ROOT . '/includes/currency_formatter.php';

wallos_test('automatic decimals keep ICU digits for ISO codes and widen for unknown codes', function () {
    unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);
    CurrencyFormatter::useCurrencies([]);
    assert_same('$52.10', CurrencyFormatter::format(52.1, 'USD'), 'dollars keep two digits');
    assert_same('¥5', CurrencyFormatter::format(5, 'JPY'), 'yen has none');
    assert_contains('0.0008', CurrencyFormatter::format(0.0008, 'BTC'), 'BTC is not rounded to 0.00');
    assert_contains('1.50', CurrencyFormatter::format(1.5, 'BTC'), 'BTC shows at least two digits');
    assert_same(8, CurrencyFormatter::inputDecimals('BTC'), 'BTC inputs accept 8 digits');
    assert_same(2, CurrencyFormatter::inputDecimals('EUR'), 'EUR inputs accept 2');
});

wallos_test('a currency set to zero decimals drops the .00 everywhere it is formatted', function () {
    unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);
    CurrencyFormatter::useCurrencies([['code' => 'COP', 'decimals' => 0], ['code' => 'USD', 'decimals' => null]]);
    $formatted = CurrencyFormatter::format(5000.4, 'COP');
    assert_contains('5,000', $formatted, 'amount kept');
    assert_not_contains('.', $formatted, 'no decimals');
    assert_same('$52.10', CurrencyFormatter::format(52.1, 'USD'), 'other currencies unaffected');
    CurrencyFormatter::useCurrencies([]);
});

wallos_test('formatPrice uses the row currency decimals and symbol', function () {
    unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);
    $currencies = [['code' => 'BTC', 'symbol' => '₿', 'decimals' => 4], ['code' => 'USD', 'symbol' => '$', 'decimals' => null]];
    $formatted = formatPrice(0.00081, 'BTC', $currencies);
    assert_contains('₿', $formatted, 'symbol replaces the code');
    assert_contains('0.0008', $formatted, 'four decimals');
    assert_not_contains('0.00081', $formatted, 'rounded to four');
});

wallos_test('decimal settings are validated and prices round to them', function () {
    assert_same(null, CurrencyFormatter::sanitizeDecimals('auto'), 'auto');
    assert_same(null, CurrencyFormatter::sanitizeDecimals(9), 'too many');
    assert_same(null, CurrencyFormatter::sanitizeDecimals(-1), 'negative');
    assert_same(3, CurrencyFormatter::sanitizeDecimals('3'), 'numeric string');
    assert_equals(0.0013, wallos_round_price(0.00125, 'BTC', 4), 'rounded to the setting');
    assert_equals(5000.0, wallos_round_price(5000.4, 'COP', 0), 'whole pesos');
    assert_equals(9.99, wallos_round_price(9.991, 'USD'), 'ISO default');
});

wallos_test('calendar exports print the currency precision, not always two digits', function () {
    assert_same('0.0008', wallos_plain_price(0.0008, 'BTC'), 'automatic crypto');
    assert_same('1.50', wallos_plain_price(1.5, 'BTC'), 'at least two digits');
    assert_same('5,000', wallos_plain_price(5000, 'COP', 0), 'zero decimals');
    assert_same('9.99', wallos_plain_price(9.99, 'USD'), 'ISO default');
});

wallos_test('migration 000070 adds automatic decimals to every currency', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    $db->exec("INSERT INTO currencies (name, symbol, code, rate, user_id) VALUES ('Bitcoin', '₿', 'BTC', 1, 1)");
    assert_same(null, $db->querySingle("SELECT decimals FROM currencies WHERE code = 'BTC'"), 'automatic by default');
    $db->close();
});
