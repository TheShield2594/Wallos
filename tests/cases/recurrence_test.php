<?php
/*
  The recurrence engine (includes/recurrence.php): calendar-anchored months,
  fixed-term plans, and the projections the calendar, budget and stats use.

  The anchor vectors at the top are shared with the JavaScript port in
  scripts/subscriptions.js (stepOccurrence), which the last case runs in Node
  when it is installed.
*/

require_once WALLOS_ROOT . '/includes/recurrence.php';

/**
 * @return array a monthly subscription row with sensible defaults
 */
function recurrence_subscription(array $fields = [])
{
    return array_merge([
        'id' => 1,
        'user_id' => 1,
        'cycle' => 3,
        'frequency' => 1,
        'next_payment' => '2026-01-31',
        'start_date' => null,
        'billing_anchor_day' => null,
        'auto_renew' => 1,
        'inactive' => 0,
        'end_date' => null,
        'total_payments' => null,
        'payments_made' => 0,
        'price' => 10.0,
        'currency_id' => 1,
    ], $fields);
}

/**
 * @param DateTime[] $dates
 * @return string[]
 */
function recurrence_ymd(array $dates)
{
    return array_map(function ($date) {
        return $date->format('Y-m-d');
    }, $dates);
}

/**
 * Shared with the JavaScript port: [from, cycle, frequency, steps, anchor, expected].
 */
function recurrence_shared_vectors()
{
    return [
        ['2026-02-28', 3, 1, 1, 31, '2026-03-31'],
        ['2026-01-31', 3, 1, 1, 31, '2026-02-28'],
        ['2024-01-31', 3, 1, 1, 31, '2024-02-29'],
        ['2026-04-30', 3, 1, 1, 31, '2026-05-31'],
        ['2026-02-28', 3, 2, 1, 30, '2026-04-30'],
        ['2026-03-31', 3, 1, -1, 31, '2026-02-28'],
        ['2025-02-28', 4, 1, 1, 29, '2026-02-28'],
        ['2027-02-28', 4, 1, 1, 29, '2028-02-29'],
        ['2026-01-31', 1, 45, 1, null, '2026-03-17'],
        ['2026-01-31', 2, 2, 1, null, '2026-02-14'],
    ];
}

wallos_test('a monthly anchor of the 31st returns to the 31st after a short month', function () {
    $subscription = recurrence_subscription(['billing_anchor_day' => 31, 'next_payment' => '2026-01-31']);
    $dates = [];
    $current = new DateTime('2026-01-31');
    for ($i = 0; $i < 5; $i++) {
        $current = wallos_next_occurrence($subscription, $current);
        $dates[] = $current->format('Y-m-d');
    }
    assert_same(['2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31', '2026-06-30'], $dates,
        'stepping one occurrence at a time never settles on the 28th');
});

wallos_test('the shared anchor vectors hold in PHP', function () {
    foreach (recurrence_shared_vectors() as [$from, $cycle, $frequency, $steps, $anchor, $expected]) {
        $subscription = recurrence_subscription(['cycle' => $cycle, 'frequency' => $frequency, 'billing_anchor_day' => $anchor]);
        assert_same($expected, wallos_step_occurrence(new DateTime($from), $subscription, $steps)->format('Y-m-d'),
            "$from + $steps x $frequency (cycle $cycle, anchor " . var_export($anchor, true) . ')');
    }
});

wallos_test('the anchor is inferred from the dates when none is stored', function () {
    assert_same(15, wallos_infer_anchor_day('2026-03-15', '2026-01-15', 3), 'an ordinary date anchors on its own day');
    assert_same(31, wallos_infer_anchor_day('2026-02-28', '2026-01-31', 3), 'the last day of a short month takes the start day');
    assert_same(31, wallos_infer_anchor_day('2026-03-03', '2026-01-31', 3), 'a date rolled over by the old arithmetic takes the start day');
    assert_same(3, wallos_infer_anchor_day('2026-03-03', '2026-01-03', 3), 'a genuine 3rd-of-month bill keeps the 3rd');
    assert_same(1, wallos_infer_anchor_day('2026-03-01', '2026-01-15', 3), 'a billing date the user moved earlier is kept');
    assert_same(null, wallos_infer_anchor_day('2026-03-03', '2026-01-31', 2), 'weekly subscriptions have no anchor');

    assert_same(31, wallos_anchor_day_for_save('2026-02-28', null, 3, 31), 'a stored anchor is kept while next_payment still sits on it');
    assert_same(15, wallos_anchor_day_for_save('2026-02-15', null, 3, 31), 'moving next_payment off the anchor re-anchors');
});

wallos_test('the cron schedule advances past every passed date and lands back on the anchor', function () {
    // A row that drifted to the 3rd under the old arithmetic, anchored to the 31st.
    $subscription = recurrence_subscription(['next_payment' => '2026-03-03', 'billing_anchor_day' => 31]);
    $advance = wallos_advance_schedule($subscription, new DateTime('2026-05-10'));
    assert_same(['2026-03-03', '2026-04-30'], $advance['due_dates'], 'both passed dates are reported');
    assert_same('2026-05-31', $advance['next_payment'], 'the next payment is back on the 31st');
    assert_false_like($advance['ended'], 'an open-ended subscription never ends');

    $notDue = wallos_advance_schedule(recurrence_subscription(['next_payment' => '2026-06-30']), new DateTime('2026-05-10'));
    assert_same([], $notDue['due_dates'], 'nothing advances before the payment date');
});

function assert_false_like($value, $message)
{
    assert_true(!$value, $message);
}

wallos_test('a fixed-term plan ends after its final payment', function () {
    $subscription = recurrence_subscription([
        'next_payment' => '2026-02-15', 'end_date' => '2026-04-15', 'total_payments' => 3, 'payments_made' => 0,
    ]);
    $advance = wallos_advance_schedule($subscription, new DateTime('2026-09-01'));
    assert_same(['2026-02-15', '2026-03-15', '2026-04-15'], $advance['due_dates'], 'exactly the three plan payments pass');
    assert_true($advance['ended'], 'the plan is reported as ended');
    assert_same('2026-04-15', $advance['next_payment'], 'next_payment stays on the final payment');

    $midway = wallos_advance_schedule($subscription, new DateTime('2026-03-20'));
    assert_true(!$midway['ended'], 'a plan with payments left is still running');
    assert_same('2026-04-15', $midway['next_payment'], 'the final payment is still ahead');
});

wallos_test('plan fields derive the end date from a count and the count from an end date', function () {
    $base = recurrence_subscription(['next_payment' => '2026-03-31', 'start_date' => '2026-01-31', 'billing_anchor_day' => 31]);

    $byCount = wallos_plan_fields($base, 'count', null, 12);
    assert_same(2, $byCount['payments_made'], 'Jan 31 and Feb 28 were already paid');
    assert_same('2026-12-31', $byCount['end_date'], 'payment 12 of a plan from Jan 31 is Dec 31');
    assert_same(12, $byCount['total_payments'], 'the count is kept');

    $byDate = wallos_plan_fields($base, 'date', '2026-06-30', null);
    assert_same(6, $byDate['total_payments'], 'Jan through Jun is six payments');
    assert_same('2026-06-30', $byDate['end_date'], 'the date is kept');

    $never = wallos_plan_fields($base, 'never', '2026-06-30', 12);
    assert_same(null, $never['end_date'], 'an open-ended subscription has no end date');
    assert_same(null, $never['total_payments'], 'nor a payment count');
});

wallos_test('projections respect the start date, the plan end and manual renewals', function () {
    $subscription = recurrence_subscription([
        'next_payment' => '2026-03-15', 'start_date' => '2026-02-15', 'end_date' => '2026-05-15',
    ]);
    assert_same(['2026-02-15', '2026-03-15', '2026-04-15', '2026-05-15'],
        recurrence_ymd(wallos_occurrences_in_range($subscription, new DateTime('2026-01-01'), new DateTime('2026-12-31'))),
        'nothing before the start date or after the final payment');

    $manual = recurrence_subscription(['next_payment' => '2026-03-15', 'auto_renew' => 0]);
    assert_same(['2026-03-15'],
        recurrence_ymd(wallos_occurrences_in_range($manual, new DateTime('2026-01-01'), new DateTime('2026-12-31'), false)),
        'a manual renewal only counts its next payment when asked not to project');
    assert_same(10,
        count(wallos_occurrences_in_range($manual, new DateTime('2026-03-01'), new DateTime('2026-12-31'))),
        'the calendar still projects manual renewals');

    $oneTime = recurrence_subscription(['cycle' => 5, 'next_payment' => '2026-03-15']);
    assert_same(['2026-03-15'],
        recurrence_ymd(wallos_occurrences_in_range($oneTime, new DateTime('2026-01-01'), new DateTime('2026-12-31'))),
        'a one-time payment appears once');
});

wallos_test('cycle progress is measured against the real calendar cycle', function () {
    $subscription = recurrence_subscription(['next_payment' => '2026-03-01', 'billing_anchor_day' => 1]);
    // Feb 1 -> Mar 1 is 28 days; Feb 15 is 14 days in.
    assert_same(50, wallos_cycle_progress($subscription, new DateTime('2026-02-15')), 'half way through February');
    // A stale next_payment far in the past still measures the current cycle.
    $stale = recurrence_subscription(['next_payment' => '2025-06-01', 'billing_anchor_day' => 1]);
    assert_same(50, wallos_cycle_progress($stale, new DateTime('2026-02-15')), 'a stale date is walked forward');
});

wallos_test('the migration backfills anchors, preferring the start day for rolled-over and month-end dates', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    $db->exec('ALTER TABLE subscriptions DROP COLUMN billing_anchor_day');

    $rows = [
        ['Drifted', '2026-12-03', '2025-01-31', 3],
        ['MonthEnd', '2027-02-28', '2025-01-30', 3],
        ['Ordinary', '2026-12-15', '2025-01-15', 3],
        ['NoStart', '2026-12-31', null, 3],
        ['Weekly', '2026-12-03', '2025-01-31', 2],
    ];
    $stmt = $db->prepare('INSERT INTO subscriptions (user_id, name, price, currency_id, next_payment, start_date, cycle, frequency)
                          VALUES (1, :name, 5, 1, :next, :start, :cycle, 1)');
    foreach ($rows as [$name, $next, $start, $cycle]) {
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->bindValue(':next', $next, SQLITE3_TEXT);
        $stmt->bindValue(':start', $start, $start === null ? SQLITE3_NULL : SQLITE3_TEXT);
        $stmt->bindValue(':cycle', $cycle, SQLITE3_INTEGER);
        $stmt->execute();
        $stmt->reset();
    }

    require WALLOS_ROOT . '/migrations/000062.php';
    require WALLOS_ROOT . '/migrations/000062.php'; // safe to run twice

    $anchors = [];
    $result = $db->query('SELECT name, billing_anchor_day FROM subscriptions');
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $anchors[$row['name']] = $row['billing_anchor_day'];
    }
    assert_same(['Drifted' => 31, 'MonthEnd' => 30, 'Ordinary' => 15, 'NoStart' => 31, 'Weekly' => null], $anchors,
        'each row gets the anchor it should');
    $db->close();
});

wallos_test('the JavaScript port of the anchor steps matches PHP', function () {
    $node = trim((string) shell_exec('command -v node 2>/dev/null'));
    if ($node === '') {
        assert_true(true, 'node is not installed; skipped');
        return;
    }
    $script = WALLOS_TEST_TMP . '/recurrence-' . uniqid() . '.js';
    file_put_contents($script, 'global.document = { addEventListener() {}, querySelector() { return null; }, querySelectorAll() { return []; }, getElementById() { return null; } };'
        . 'global.window = {};' . "\n"
        . file_get_contents(WALLOS_ROOT . '/scripts/subscriptions.js') . "\n"
        . 'const vectors = ' . json_encode(recurrence_shared_vectors()) . ';'
        . 'const out = vectors.map(([from, cycle, frequency, steps, anchor]) => formatFormDate(stepOccurrence(parseFormDate(from), cycle, frequency, steps, anchor)));'
        . 'process.stdout.write(JSON.stringify(out));');
    $output = shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($script) . ' 2>&1');
    @unlink($script);

    assert_same(array_column(recurrence_shared_vectors(), 5), json_decode((string) $output, true),
        'stepOccurrence() in scripts/subscriptions.js gives the same dates (' . trim((string) $output) . ')');
});
