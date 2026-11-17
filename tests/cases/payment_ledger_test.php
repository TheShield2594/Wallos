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

    $badge = wallos_paid_badge_date($subscription, wallos_ledger_last_paid_map($db, 1)[$subscription['id']] ?? null);
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
