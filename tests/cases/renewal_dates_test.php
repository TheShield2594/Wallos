<?php
/*
  Monthly and yearly renewals used DateTime::add(P1M) / strtotime('+1 month'),
  which roll surplus days into the next month: Jan 31 + 1 month = Mar 3, after
  which the subscription renews on the 3rd for good. includes/renewal_dates.php
  clamps to the end of the month and computes every occurrence from the same
  base date, so a run from Jan 31 keeps returning to the 31st when it can.
*/

require_once WALLOS_ROOT . '/includes/renewal_dates.php';

/**
 * @return string[]
 */
function renewal_dates_series($base, $cycle, $frequency, $count)
{
    $dates = [];
    for ($steps = 1; $steps <= $count; $steps++) {
        $dates[] = wallos_renewal_date(new DateTime($base), $cycle, $frequency, $steps)->format('Y-m-d');
    }

    return $dates;
}

wallos_test('monthly renewals from the 31st clamp to the end of shorter months', function () {
    assert_same(
        ['2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31'],
        renewal_dates_series('2026-01-31', 'Monthly', 1, 4),
        'Jan 31 renews on the last day of each month instead of drifting to Mar 3'
    );
    assert_same(
        ['2024-02-29', '2024-03-30'],
        renewal_dates_series('2024-01-30', 'Monthly', 1, 2),
        'a leap-year February takes the 29th, and the 30th comes back in March'
    );
    assert_same(
        ['2026-03-31', '2026-05-31', '2026-07-31', '2026-09-30'],
        renewal_dates_series('2026-01-31', 'Monthly', 2, 4),
        'a two-month frequency clamps the same way'
    );
    assert_same('2025-12-31', wallos_renewal_date(new DateTime('2026-01-31'), 'Monthly', 1, -1)->format('Y-m-d'),
        'stepping backwards works across a year boundary');
});

wallos_test('yearly renewals from Feb 29 land on Feb 28 and return in leap years', function () {
    assert_same(
        ['2025-02-28', '2026-02-28', '2027-02-28', '2028-02-29'],
        renewal_dates_series('2024-02-29', 'Yearly', 1, 4),
        'Feb 29 renews on Feb 28 instead of Mar 1'
    );
});

wallos_test('daily and weekly renewals are unchanged', function () {
    assert_same(['2026-02-01', '2026-02-02'], renewal_dates_series('2026-01-31', 'Daily', 1, 2), 'daily steps one day');
    assert_same(['2026-02-14', '2026-02-28'], renewal_dates_series('2026-01-31', 'Weekly', 2, 2), 'weekly steps whole weeks');
});

wallos_test('the renewal cron and manual renew endpoint use the clamping helper', function () {
    foreach (['endpoints/cronjobs/updatenextpayment.php', 'endpoints/subscription/renew.php', 'calendar.php'] as $file) {
        $source = file_get_contents(WALLOS_ROOT . '/' . $file);
        assert_contains('wallos_renewal_date(', $source, $file . ' advances dates through wallos_renewal_date()');
        assert_true(strpos($source, 'new DateInterval(') === false && strpos($source, '{$frequency} months') === false,
            $file . ' no longer adds raw month intervals');
    }
});
