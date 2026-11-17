<?php
/*
  Date format setting (includes/date_format.php, issue #32).
*/

require_once WALLOS_ROOT . '/includes/date_format.php';

wallos_test('the date format falls back to automatic', function () {
    assert_same('auto', wallos_sanitize_date_format(null), 'no setting yet');
    assert_same('auto', wallos_sanitize_date_format('ddmmyyyy'), 'unknown value');
    assert_same('dmy', wallos_sanitize_date_format('dmy'), 'valid value kept');
});

wallos_test('explicit date formats always show the day, and the year only when it is not this year', function () {
    $thisYear = date('Y');
    $other = ((int) $thisYear + 1) . '-03-07';
    assert_same('07/03', tally_format_date($thisYear . '-03-07', 'short', 'en', 'dmy'), 'day first this year');
    assert_same('07/03/' . ((int) $thisYear + 1), tally_format_date($other, 'short', 'en', 'dmy'), 'other year adds the year');
    assert_same('03/07/' . ((int) $thisYear + 1), tally_format_date($other, 'long', 'en', 'mdy'), 'month first');
    assert_same($thisYear . '-03-07', tally_format_date($thisYear . '-03-07', 'short', 'en', 'iso'), 'ISO always has the year');
    assert_same('7 Mar ' . ((int) $thisYear + 1), tally_format_date($other, 'short', 'en', 'd_mon_y'), 'day month year');
    assert_same('Mar 7, ' . ((int) $thisYear + 1), tally_format_date($other, 'short', 'en', 'mon_d_y'), 'month day, year');
    assert_same('Mar 7', tally_format_date($thisYear . '-03-07', 'short', 'en', 'mon_d_y'), 'no year this year');
});

wallos_test('automatic dates keep the day for other years and name months in the UI language', function () {
    $other = ((int) date('Y') + 1) . '-12-31';
    $formatted = tally_format_date($other, 'short', 'en', 'auto');
    assert_contains('31', $formatted, 'the day is no longer dropped for another year');
    assert_contains((string) ((int) date('Y') + 1), $formatted, 'the year is shown');
    assert_contains('Dez', tally_format_date($other, 'long', 'de', 'auto'), 'German month name');
});

wallos_test('month labels follow the date format', function () {
    assert_same('2026-12', tally_format_date('2026-12-01', 'month', 'en', 'iso'), 'ISO month');
    assert_same('12/26', tally_format_date('2026-12-01', 'month', 'en', 'dmy'), 'numeric month');
    assert_same('Dec 26', tally_format_date('2026-12-01', 'month', 'en', 'mon_d_y'), 'named month');
});

wallos_test('a period label carries the year on both ends when it crosses one', function () {
    $label = tally_format_date_range(new DateTime('2030-12-20'), new DateTime('2031-01-19'), 'en', 'dmy');
    assert_same('20/12/2030 - 19/01/2031', $label, 'cross-year period');
    $year = date('Y');
    assert_same('01/03 - 31/03', tally_format_date_range(new DateTime("$year-03-01"), new DateTime("$year-03-31"), 'en', 'dmy'), 'same year');
});

wallos_test('migration 000069 adds the date format with automatic as default', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    $db->exec("INSERT INTO settings (user_id) VALUES (1)");
    assert_same('auto', $db->querySingle('SELECT date_format FROM settings WHERE user_id = 1'), 'default format');
    $db->close();
});

wallos_test('no page keeps its own copy of formatDate', function () {
    foreach (['index.php', 'includes/list_subscriptions.php'] as $file) {
        assert_not_contains('function formatDate(', file_get_contents(WALLOS_ROOT . '/' . $file), $file . ' uses includes/date_format.php');
    }
    assert_not_contains("format('M j')", file_get_contents(WALLOS_ROOT . '/includes/budget_period_calculations.php'), 'budget labels are localized');
});
