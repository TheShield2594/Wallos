<?php
/*
  Payment ledger, "Mark paid" and fixed-term plans against the real schema
  (includes/payment_ledger.php).
*/

require_once WALLOS_ROOT . '/includes/payment_ledger.php';

/**
 * Inserts a subscription for user 1 and returns its row.
 *
 * @return array
 */
function ledger_subscription(SQLite3 $db, array $fields = [])
{
    $row = array_merge([
        'user_id' => 1,
        'name' => 'Power',
        'price' => 40.0,
        'currency_id' => wallos_test_currency_id(1, 0),
        'next_payment' => '2026-03-15',
        'start_date' => null,
        'cycle' => 3,
        'frequency' => 1,
        'auto_renew' => 1,
        'inactive' => 0,
        'billing_anchor_day' => null,
        'renew_from_action' => 0,
        'variable_amount' => 0,
        'end_date' => null,
        'total_payments' => null,
        'payments_made' => 0,
    ], $fields);

    $columns = array_keys($row);
    $stmt = $db->prepare('INSERT INTO subscriptions (' . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')');
    foreach ($row as $column => $value) {
        $stmt->bindValue(':' . $column, $value, $value === null ? SQLITE3_NULL : (is_float($value) ? SQLITE3_FLOAT : (is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT)));
    }
    $stmt->execute();

    return wallos_ledger_load_subscription($db, 1, $db->lastInsertRowID());
}

function ledger_reload(SQLite3 $db, array $subscription)
{
    return wallos_ledger_load_subscription($db, $subscription['user_id'], $subscription['id']);
}

function ledger_rows(SQLite3 $db, array $subscription)
{
    return array_reverse(wallos_ledger_list($db, $subscription['user_id'], $subscription['id'], 100));
}

function ledger_open_database()
{
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    wallos_test_create_user($db, 2, 'bob');

    return $db;
}

wallos_test('the cron writes one assumed row per passed cycle and is idempotent', function () {
    $db = ledger_open_database();
    $subscription = ledger_subscription($db, ['next_payment' => '2026-01-31', 'billing_anchor_day' => 31]);

    wallos_apply_schedule_advance($db, $subscription, new DateTime('2026-04-10'));
    $after = ledger_reload($db, $subscription);
    assert_same('2026-04-30', $after['next_payment'], 'next payment is on the anchor');
    assert_same(3, (int) $after['payments_made'], 'three cycles counted');

    $rows = ledger_rows($db, $subscription);
    assert_same(['2026-01-31', '2026-02-28', '2026-03-31'], array_column($rows, 'due_date'), 'one row per passed due date');
    assert_same(['assumed', 'assumed', 'assumed'], array_column($rows, 'status'), 'rows are unconfirmed');
    assert_same([40.0, 40.0, 40.0], array_column($rows, 'amount'), 'at the list price');

    // Re-recording the same cycles never duplicates them.
    wallos_ledger_record_cycles($db, $subscription, ['2026-01-31', '2026-02-28']);
    assert_same(3, count(ledger_rows($db, $subscription)), 'no duplicate rows');
    $db->close();
});

wallos_test('a variable-amount subscription asks for the amount of each cycle', function () {
    $db = ledger_open_database();
    $subscription = ledger_subscription($db, ['next_payment' => '2026-03-15', 'variable_amount' => 1]);
    wallos_apply_schedule_advance($db, $subscription, new DateTime('2026-03-20'));

    $rows = ledger_rows($db, $subscription);
    assert_same('pending_amount', $rows[0]['status'], 'the cycle waits for an amount');
    assert_same(null, $rows[0]['amount'], 'no amount is guessed');
    assert_same(1, count(wallos_ledger_open_items($db, 1, false)), 'it is listed for review');

    assert_true(wallos_ledger_save($db, 1, $rows[0]['id'], '41.37', '2026-03-18', 'meter read'), 'the amount is saved');
    $saved = ledger_rows($db, $subscription)[0];
    assert_same('paid', $saved['status'], 'entering the amount confirms the cycle');
    assert_same(41.37, $saved['amount'], 'with the amount entered');
    assert_same(0, count(wallos_ledger_open_items($db, 1, false)), 'nothing is left to review');

    assert_true(!wallos_ledger_save($db, 2, $rows[0]['id'], '1', null, ''), 'another user cannot edit the row');
    $db->close();
});

wallos_test('unconfirmed renewals are only listed when the user asked to confirm them', function () {
    $db = ledger_open_database();
    $subscription = ledger_subscription($db, ['next_payment' => '2026-03-15']);
    wallos_apply_schedule_advance($db, $subscription, new DateTime('2026-03-20'));

    assert_same(0, count(wallos_ledger_open_items($db, 1, false)), 'assumed rows stay quiet by default');
    assert_same(1, count(wallos_ledger_open_items($db, 1, true)), 'and are listed with the setting on');
    $db->close();
});

wallos_test('marking a manual renewal as paid moves it on one cycle and can be undone', function () {
    $db = ledger_open_database();
    $subscription = ledger_subscription($db, ['next_payment' => '2026-01-31', 'auto_renew' => 0, 'billing_anchor_day' => 31]);

    $outcome = wallos_mark_paid($db, $subscription, new DateTime('2026-04-02'), 39.5);
    assert_true($outcome['success'], 'the payment is recorded');
    $after = ledger_reload($db, $subscription);
    assert_same('2026-02-28', $after['next_payment'], 'one cycle per confirmation, so every cycle gets its row');
    assert_same(1, (int) $after['payments_made'], 'counted once');
    $rows = ledger_rows($db, $subscription);
    assert_same(['2026-01-31'], array_column($rows, 'due_date'), 'the confirmed cycle is in the ledger');
    assert_same('2026-04-02', $rows[0]['paid_date'], 'paid today');
    assert_same(39.5, $rows[0]['amount'], 'with the amount given');

    wallos_mark_paid($db, $after, new DateTime('2026-04-02'));
    $after = ledger_reload($db, $subscription);
    assert_same('2026-03-31', $after['next_payment'], 'the second confirmation returns to the 31st');

    // Only the most recent payment can be taken back.
    assert_same('not_latest', wallos_undo_paid($db, $after, '2026-01-31')['error'] ?? null, 'an older payment is refused');
    assert_true(wallos_undo_paid($db, $after, '2026-02-28')['success'], 'the latest is undone');
    $undone = ledger_reload($db, $subscription);
    assert_same('2026-02-28', $undone['next_payment'], 'next_payment goes back to the undone cycle');
    assert_same(1, (int) $undone['payments_made'], 'and the count with it');
    assert_same(31, (int) $undone['billing_anchor_day'], 'the anchor survives');
    $db->close();
});

wallos_test('renewal can restart the cycle from the day of payment', function () {
    $db = ledger_open_database();
    $subscription = ledger_subscription($db, ['next_payment' => '2026-03-01', 'auto_renew' => 0, 'renew_from_action' => 1, 'billing_anchor_day' => 1]);

    wallos_mark_paid($db, $subscription, new DateTime('2026-03-20'));
    $after = ledger_reload($db, $subscription);
    assert_same('2026-04-20', $after['next_payment'], 'next payment is a cycle after the day it was paid');
    assert_same(20, (int) $after['billing_anchor_day'], 'and the anchor moves to that day');
    assert_same('2026-03-01', ledger_rows($db, $subscription)[0]['due_date'], 'the payment is booked against the cycle it paid');
    $db->close();
});

wallos_test('marking an automatic renewal as paid confirms the last cycle or pays the next one early', function () {
    $db = ledger_open_database();
    $subscription = ledger_subscription($db, ['next_payment' => '2026-03-15']);
    wallos_apply_schedule_advance($db, $subscription, new DateTime('2026-03-20'));
    $subscription = ledger_reload($db, $subscription);

    wallos_mark_paid($db, $subscription, new DateTime('2026-03-21'));
    $rows = ledger_rows($db, $subscription);
    assert_same('paid', $rows[0]['status'], 'the assumed cycle is confirmed');
    assert_same('2026-04-15', ledger_reload($db, $subscription)['next_payment'], 'the schedule is not moved');

    $badge = wallos_paid_badge_date($subscription, wallos_ledger_last_paid_map($db, 1)[$subscription['id']] ?? null, new DateTime('2026-03-21'));
    assert_same('2026-03-21', $badge, 'the row shows Paid until the next due date');

    // Paying early: the upcoming cycle is recorded, the cron keeps it.
    wallos_mark_paid($db, $subscription, new DateTime('2026-04-10'), 42);
    wallos_apply_schedule_advance($db, ledger_reload($db, $subscription), new DateTime('2026-04-20'));
    $early = array_values(array_filter(ledger_rows($db, $subscription), function ($row) {
        return $row['due_date'] === '2026-04-15';
    }))[0];
    assert_same('paid', $early['status'], 'the cron did not overwrite the early payment');
    assert_same(42.0, $early['amount'], 'the amount paid early is kept');
    assert_same('2026-05-15', ledger_reload($db, $subscription)['next_payment'], 'the cycle advanced once');

    // Undo of a cycle the cron already passed puts it back to unconfirmed.
    assert_true(wallos_undo_paid($db, ledger_reload($db, $subscription), '2026-04-15')['success'], 'undone');
    $reverted = array_values(array_filter(ledger_rows($db, $subscription), function ($row) {
        return $row['due_date'] === '2026-04-15';
    }))[0];
    assert_same('assumed', $reverted['status'], 'the cycle is unconfirmed again');
    $db->close();
});

wallos_test('a fixed-term plan completes on its final payment and is not a saving', function () {
    $db = ledger_open_database();
    $plan = ledger_subscription($db, [
        'next_payment' => '2026-01-10', 'end_date' => '2026-03-10', 'total_payments' => 3,
    ]);
    wallos_apply_schedule_advance($db, $plan, new DateTime('2026-06-01'));
    $after = ledger_reload($db, $plan);
    assert_same(1, (int) $after['inactive'], 'the finished plan stops billing');
    assert_same('completed', $after['ended_reason'], 'and is marked as completed');
    assert_same(3, (int) $after['payments_made'], 'all three payments were counted');
    assert_same('2026-03-10', $after['next_payment'], 'the last payment date is kept');
    assert_same(3, count(ledger_rows($db, $plan)), 'no payment is recorded after the end');

    $manualPlan = ledger_subscription($db, [
        'next_payment' => '2026-02-10', 'end_date' => '2026-03-10', 'total_payments' => 2, 'payments_made' => 0, 'auto_renew' => 0,
    ]);
    wallos_mark_paid($db, $manualPlan, new DateTime('2026-02-10'));
    assert_same(0, (int) ledger_reload($db, $manualPlan)['inactive'], 'one payment left');
    $outcome = wallos_mark_paid($db, ledger_reload($db, $manualPlan), new DateTime('2026-03-10'));
    assert_true($outcome['ended'], 'the final manual payment completes the plan');
    assert_same('completed', ledger_reload($db, $manualPlan)['ended_reason'], 'marked completed');

    // Undoing the final payment reopens the plan.
    wallos_undo_paid($db, ledger_reload($db, $manualPlan), '2026-03-10');
    $reopened = ledger_reload($db, $manualPlan);
    assert_same(0, (int) $reopened['inactive'], 'the plan is active again');
    assert_same(null, $reopened['ended_reason'], 'and no longer completed');

    // stats_calculations.php skips completed plans when it adds up savings.
    $stats = file_get_contents(WALLOS_ROOT . '/includes/stats_calculations.php');
    assert_contains("=== 'completed'", $stats, 'completed plans are kept out of the savings total');
    assert_true((bool) preg_match('/SELECT [^"]*ended_reason[^"]*end_date|SELECT [^"]*end_date[^"]*ended_reason/', $stats),
        'and the stats query actually selects the plan columns it checks');
    $db->close();
});

wallos_test('monthly totals add up actual payments in the main currency', function () {
    $db = ledger_open_database();
    $eur = ledger_subscription($db, ['next_payment' => '2026-01-15']);
    $usd = ledger_subscription($db, ['next_payment' => '2026-01-20', 'price' => 11.0, 'currency_id' => wallos_test_currency_id(1, 1)]);
    wallos_apply_schedule_advance($db, $eur, new DateTime('2026-02-28'));
    wallos_apply_schedule_advance($db, $usd, new DateTime('2026-02-28'));

    $totals = wallos_ledger_monthly_totals($db, 1, '2025-12', '2026-03');
    assert_same(['2025-12', '2026-01', '2026-02', '2026-03'], array_keys($totals), 'every month in the range is present');
    assert_true(abs($totals['2026-01'] - 50.0) < 0.001, 'EUR 40 + USD 11 at 1.1 = EUR 50 (got ' . $totals['2026-01'] . ')');
    assert_same(0.0, $totals['2025-12'], 'an empty month is zero');
    assert_same([], array_filter(wallos_ledger_monthly_totals($db, 2, '2026-01', '2026-02')), 'another user sees nothing');
    $db->close();
});

wallos_test('deleting a subscription removes its payment history', function () {
    $source = file_get_contents(WALLOS_ROOT . '/endpoints/subscription/delete.php');
    assert_contains('DELETE FROM subscription_payments', $source, 'the delete endpoint cascades to the ledger');
    $api = file_get_contents(WALLOS_ROOT . '/api/subscriptions/set_subscriptions.php');
    assert_contains('DELETE FROM subscription_payments', $api, 'so does the API delete');
});

wallos_test('review fixes: confirming in another currency, badge expiry and paid-date totals', function () {
    $db = ledger_open_database();
    $subscription = ledger_subscription($db, ['next_payment' => '2026-03-15']);
    wallos_apply_schedule_advance($db, $subscription, new DateTime('2026-03-20'));

    // The subscription moves to USD before the assumed EUR cycle is confirmed.
    $db->exec('UPDATE subscriptions SET currency_id = ' . wallos_test_currency_id(1, 1) . ' WHERE id = ' . (int) $subscription['id']);
    wallos_mark_paid($db, ledger_reload($db, $subscription), new DateTime('2026-04-02'), 44);
    $row = ledger_rows($db, $subscription)[0];
    assert_same(wallos_test_currency_id(1, 1), (int) $row['currency_id'], 'the confirmed amount is stored with its own currency');

    // Paid on Apr 2 for the Mar 15 cycle: counted in April.
    $totals = wallos_ledger_monthly_totals($db, 1, '2026-03', '2026-04');
    assert_same(0.0, $totals['2026-03'], 'nothing was paid in March');
    assert_true($totals['2026-04'] > 0, 'the payment counts in the month it was made');

    // A manual renewal confirmed one cycle but still behind is overdue, not paid.
    $manual = ledger_subscription($db, ['next_payment' => '2026-01-31', 'auto_renew' => 0, 'billing_anchor_day' => 31]);
    wallos_mark_paid($db, $manual, new DateTime('2026-04-02'));
    $manual = ledger_reload($db, $manual);
    $lastPaid = wallos_ledger_last_paid_map($db, 1)[$manual['id']] ?? null;
    assert_same(null, wallos_paid_badge_date($manual, $lastPaid, new DateTime('2026-04-02')), 'no badge once the next due date has passed');
    assert_same('2026-04-02', wallos_paid_badge_date($manual, $lastPaid, new DateTime('2026-02-10')), 'the badge shows before it');
    $db->close();
});

wallos_test('review fixes: undo after a restarted cycle, and undo on a completed automatic plan', function () {
    $db = ledger_open_database();
    // Paid early on Feb 1 for the Mar 15 cycle; the cycle restarts and next_payment becomes Mar 1.
    $early = ledger_subscription($db, ['next_payment' => '2026-03-15', 'auto_renew' => 0, 'renew_from_action' => 1, 'billing_anchor_day' => 15]);
    wallos_mark_paid($db, $early, new DateTime('2026-02-01'));
    assert_same('2026-03-01', ledger_reload($db, $early)['next_payment'], 'the cycle restarted');
    assert_true(wallos_undo_paid($db, $early, '2026-03-15')['success'], 'the only payment can still be undone');
    assert_same('2026-03-15', ledger_reload($db, $early)['next_payment'], 'and the schedule goes back');

    // An automatic plan whose final cycle was paid early, then completed.
    $plan = ledger_subscription($db, ['next_payment' => '2026-03-10', 'end_date' => '2026-03-10', 'total_payments' => 1]);
    wallos_mark_paid($db, $plan, new DateTime('2026-03-05'));
    wallos_apply_schedule_advance($db, ledger_reload($db, $plan), new DateTime('2026-03-20'));
    assert_same('completed', ledger_reload($db, $plan)['ended_reason'], 'the plan completed');
    assert_true(wallos_undo_paid($db, $plan, '2026-03-10')['success'], 'undone');
    $rows = ledger_rows($db, $plan);
    assert_same(1, count($rows), 'the final cycle is kept, not deleted');
    assert_same('assumed', $rows[0]['status'], 'it goes back to unconfirmed');
    $db->close();
});

wallos_test('review fixes: a count-based plan keeps its count when payments restart the cycle', function () {
    $db = ledger_open_database();
    $plan = ledger_subscription($db, [
        'next_payment' => '2026-01-15', 'auto_renew' => 0, 'renew_from_action' => 1, 'billing_anchor_day' => 15,
        'end_date' => '2026-03-15', 'total_payments' => 3, 'plan_mode' => 'count',
    ]);
    $dates = ['2026-01-15', '2026-02-20', '2026-03-20'];
    $outcomes = [];
    foreach ($dates as $date) {
        $outcomes[] = wallos_mark_paid($db, ledger_reload($db, $plan), new DateTime($date));
    }
    assert_same([false, false, true], array_column($outcomes, 'ended'), 'only the third payment ends the plan');
    $after = ledger_reload($db, $plan);
    assert_same(3, (int) $after['payments_made'], 'three payments');
    assert_same('completed', $after['ended_reason'], 'completed after the third');
    assert_same(3, count(ledger_rows($db, $plan)), 'each payment recorded');

    // A date-based plan keeps its date instead.
    $byDate = ledger_subscription($db, [
        'next_payment' => '2026-01-15', 'auto_renew' => 0, 'renew_from_action' => 1, 'billing_anchor_day' => 15,
        'end_date' => '2026-03-15', 'total_payments' => 3, 'plan_mode' => 'date',
    ]);
    wallos_mark_paid($db, $byDate, new DateTime('2026-01-15'));
    $outcome = wallos_mark_paid($db, ledger_reload($db, $byDate), new DateTime('2026-02-20'));
    assert_true($outcome['ended'], 'the next payment (Mar 20) would fall after the end date, so the plan ends');
    $db->close();
});

wallos_test('review fixes: the cron advance is atomic and works on the current row', function () {
    $path = wallos_test_database();
    $db = new SQLite3($path);
    $db->busyTimeout(5000);
    wallos_test_create_user($db, 1, 'alice');
    $subscription = ledger_subscription($db, ['next_payment' => '2026-03-15']);
    wallos_mark_paid($db, $subscription, new DateTime('2026-03-10')); // paid early

    // Another request holds the write lock (an undo in progress): the cron
    // writes nothing at all rather than a ledger row without its schedule.
    $other = new SQLite3($path);
    $other->exec('BEGIN IMMEDIATE');
    $db->busyTimeout(50);
    $blocked = wallos_apply_schedule_advance($db, $subscription, new DateTime('2026-03-20'));
    assert_true(!$blocked['success'], 'the advance reports that it did not run');
    $other->exec('ROLLBACK');
    $other->close();
    $db->busyTimeout(5000);
    assert_same('2026-03-15', ledger_reload($db, $subscription)['next_payment'], 'the schedule is untouched');
    assert_same(['paid'], array_column(ledger_rows($db, $subscription), 'status'), 'and so is the ledger');

    // The undo then runs first; the cron sees its result, not its own stale copy.
    assert_true(wallos_undo_paid($db, $subscription, '2026-03-15')['success'], 'the early payment is undone');
    $stale = $subscription; // still read before the undo
    $advance = wallos_apply_schedule_advance($db, $stale, new DateTime('2026-03-20'));
    assert_true($advance['success'], 'the next run advances');
    assert_same(['2026-03-15'], array_column(ledger_rows($db, $subscription), 'due_date'), 'the elapsed cycle has its row');
    assert_same('assumed', ledger_rows($db, $subscription)[0]['status'], 'recorded as unconfirmed');
    assert_same('2026-04-15', ledger_reload($db, $subscription)['next_payment'], 'and the schedule moved on');

    // A stale copy of a row that was already advanced does nothing more.
    $again = wallos_apply_schedule_advance($db, $stale, new DateTime('2026-03-20'));
    assert_same([], $again['due_dates'], 'no cycle is counted twice');
    assert_same(1, (int) ledger_reload($db, $subscription)['payments_made'], 'payments_made counted once');
    $db->close();
});

wallos_test('review fixes: undo follows the order of payments, not of due dates', function () {
    $db = ledger_open_database();
    // Two early payments on a subscription whose payments restart the cycle:
    // the second is for an earlier due date than the first.
    $subscription = ledger_subscription($db, ['next_payment' => '2026-03-15', 'auto_renew' => 0, 'renew_from_action' => 1, 'billing_anchor_day' => 15]);
    wallos_mark_paid($db, $subscription, new DateTime('2026-02-01'));
    assert_same('2026-03-01', ledger_reload($db, $subscription)['next_payment'], 'restarted on Feb 1');
    wallos_mark_paid($db, ledger_reload($db, $subscription), new DateTime('2026-02-02'));
    assert_same('2026-03-02', ledger_reload($db, $subscription)['next_payment'], 'restarted again on Feb 2');

    assert_same('not_latest', wallos_undo_paid($db, $subscription, '2026-03-15')['error'] ?? null,
        'the first payment is not the latest, although its due date is later');
    assert_true(wallos_undo_paid($db, $subscription, '2026-03-01')['success'], 'the second payment is undone first');
    assert_same('2026-03-01', ledger_reload($db, $subscription)['next_payment'], 'back to the schedule after the first');
    assert_true(wallos_undo_paid($db, $subscription, '2026-03-15')['success'], 'then the first');
    assert_same('2026-03-15', ledger_reload($db, $subscription)['next_payment'], 'back to the original schedule');
    assert_same(0, (int) ledger_reload($db, $subscription)['payments_made'], 'nothing counted');
    $db->close();
});

wallos_test('review fixes: a repeated payment on the same day is refused, not counted twice', function () {
    $db = ledger_open_database();
    $subscription = ledger_subscription($db, ['next_payment' => '2026-03-15', 'auto_renew' => 0, 'renew_from_action' => 1, 'billing_anchor_day' => 15]);
    $outcomes = [];
    for ($i = 0; $i < 3; $i++) {
        $outcomes[] = wallos_mark_paid($db, ledger_reload($db, $subscription), new DateTime('2026-02-01'));
    }
    assert_same([true, true, false], array_column($outcomes, 'success'), 'the third payment on Feb 1 is for an already paid cycle');
    assert_same('already_paid', $outcomes[2]['error'] ?? null, 'and says so');
    assert_same(2, (int) ledger_reload($db, $subscription)['payments_made'], 'two payments counted');
    assert_same(2, count(ledger_rows($db, $subscription)), 'two payments recorded');

    assert_true(wallos_undo_paid($db, $subscription, '2026-03-01')['success'], 'the second is undone');
    assert_true(wallos_undo_paid($db, $subscription, '2026-03-15')['success'], 'then the first');
    $after = ledger_reload($db, $subscription);
    assert_same(0, (int) $after['payments_made'], 'the count is back to zero');
    assert_same('2026-03-15', $after['next_payment'], 'and the schedule to the start');
    $db->close();
});

wallos_test('review fixes: once payments are recorded, edits keep the stored count', function () {
    $db = ledger_open_database();
    // Three-payment plan from Jan 15; the first payment, on Feb 20, restarts the cycle.
    $plan = ledger_subscription($db, [
        'next_payment' => '2026-01-15', 'start_date' => '2026-01-15', 'auto_renew' => 0, 'renew_from_action' => 1,
        'billing_anchor_day' => 15, 'end_date' => '2026-03-15', 'total_payments' => 3, 'plan_mode' => 'count',
    ]);
    wallos_mark_paid($db, $plan, new DateTime('2026-02-20'));
    $plan = ledger_reload($db, $plan);
    assert_same('2026-03-20', $plan['next_payment'], 'restarted on Feb 20');

    // The user turns the restart option off, then moves the next payment to Apr 20.
    $db->exec('UPDATE subscriptions SET renew_from_action = 0 WHERE id = ' . (int) $plan['id']);
    $plan = ledger_reload($db, $plan);
    $edit = ['next_payment' => '2026-04-20', 'start_date' => '2026-01-15', 'cycle' => 3, 'frequency' => 1];
    $keep = wallos_keep_payments_made_on_save($db, $plan, $edit);
    assert_true($keep, 'a recorded payment makes the stored count authoritative');

    $fields = wallos_plan_fields(array_merge($plan, $edit, [
        'billing_anchor_day' => wallos_anchor_day_for_save($edit['next_payment'], $edit['start_date'], 3, $plan['billing_anchor_day']),
        'keep_payments_made' => $keep,
    ]), 'count', null, 3);
    assert_same(1, $fields['payments_made'], 'still one payment made');
    assert_same('2026-05-20', $fields['end_date'], 'two left: Apr 20 and May 20');

    // Deleting the history row afterwards does not undo the payment: moving
    // the next payment still keeps the count.
    foreach (ledger_rows($db, $plan) as $row) {
        wallos_ledger_delete($db, 1, $row['id']);
    }
    assert_same(0, count(ledger_rows($db, $plan)), 'the history is empty');
    assert_true(wallos_keep_payments_made_on_save($db, $plan, $edit), 'moving next_payment never recounts');

    // Without any history, redefining where the schedule starts is counted.
    $fresh = ledger_subscription($db, ['next_payment' => '2026-03-15', 'start_date' => '2026-01-15']);
    assert_true(!wallos_keep_payments_made_on_save($db, $fresh, ['next_payment' => '2026-03-15', 'start_date' => '2025-12-15', 'cycle' => 3, 'frequency' => 1]),
        'no history and a new start date: the dates are counted');
    assert_true(wallos_keep_payments_made_on_save($db, $fresh, ['next_payment' => '2026-04-15', 'start_date' => '2026-01-15', 'cycle' => 3, 'frequency' => 1]),
        'moving next_payment alone keeps the count');
    assert_true(wallos_keep_payments_made_on_save($db, $fresh, ['next_payment' => '2026-03-15', 'start_date' => '2026-01-15', 'cycle' => 3, 'frequency' => 1]),
        'an unchanged schedule keeps the count');
    assert_true(!wallos_keep_payments_made_on_save($db, null, $edit), 'a new subscription is counted');
    $db->close();
});
