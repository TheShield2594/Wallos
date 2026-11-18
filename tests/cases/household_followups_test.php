<?php
/*
  Shared households follow-ups (issue #59): payer labels linked to real
  accounts, and reminders for the linked payer.
*/

require_once WALLOS_ROOT . '/includes/households.php';
require_once WALLOS_ROOT . '/includes/notifications/shared_reminders.php';

/**
 * alice (1) owns the household, bob (2) is an editor, erin (5) is outside.
 * alice's payer label 51 ("Bob") pays her shared subscription.
 *
 * @return array [db, subscription id]
 */
function household_followups_fixture(array $subscription = [])
{
    $db = wallos_test_open_database();
    foreach ([1 => 'alice', 2 => 'bob', 5 => 'erin'] as $id => $name) {
        wallos_test_create_user($db, $id, $name);
    }
    assert_true(wallos_household_create($db, 1, 'Home')['success'], 'household');
    assert_true(wallos_household_invite($db, 1, 'bob', 'editor')['success'], 'invite');
    assert_true(wallos_household_respond($db, 2, wallos_household_membership($db, 1)['id'], true)['success'], 'bob joins');
    $db->exec("INSERT INTO household (id, name, user_id) VALUES (50, 'Alice', 1), (51, 'Bob', 1)");

    $row = array_merge([
        'user_id' => 1, 'name' => 'Netflix', 'price' => 10, 'currency_id' => wallos_test_currency_id(1, 0),
        'next_payment' => '2026-11-25', 'cycle' => 3, 'frequency' => 1, 'inactive' => 0, 'notify' => 1,
        'notify_days_before' => 7, 'auto_renew' => 1, 'category_id' => 1, 'payer_user_id' => 51, 'payment_method_id' => 1,
        'start_date' => '2026-01-25',
    ], $subscription);
    $columns = array_keys($row);
    $stmt = $db->prepare('INSERT INTO subscriptions (' . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')');
    foreach ($row as $column => $value) {
        $stmt->bindValue(':' . $column, $value, $value === null ? SQLITE3_NULL : (is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT));
    }
    $stmt->execute();
    $id = $db->lastInsertRowID();
    assert_true(wallos_set_subscription_sharing($db, 1, $id, true)['success'], 'shared');

    return [$db, $id];
}

wallos_test('a payer label links only to another account of the household', function () {
    [$db, $id] = household_followups_fixture();

    assert_same([2 => 'bob'], wallos_household_link_candidates($db, 1), 'bob is the only candidate');
    assert_same([], wallos_household_link_candidates($db, 5), 'erin has no household');
    assert_same('not_member', wallos_household_set_payer_link($db, 1, 51, 5)['error'], 'erin cannot be linked');
    assert_same('not_member', wallos_household_set_payer_link($db, 1, 51, 1)['error'], 'nor alice herself');
    assert_same('not_found', wallos_household_set_payer_link($db, 2, 51, 1)['error'], "bob cannot link alice's label");
    assert_true(wallos_household_set_payer_link($db, 1, 51, 2)['success'], 'alice links Bob to bob');

    $shared = wallos_shared_subscriptions($db, 2);
    assert_same('bob', $shared[0]['shared_payer_name'], "bob sees his own account's name as the payer");
    assert_same(2, $shared[0]['shared_payer_linked_user_id'], 'and the link');

    assert_true(wallos_household_set_payer_link($db, 1, 51, null)['success'], 'unlinked');
    assert_same('Bob', wallos_shared_subscriptions($db, 2)[0]['shared_payer_name'], 'the label again');
    $db->close();
});

wallos_test('a payer link is cleared when either account leaves or the household goes', function () {
    [$db] = household_followups_fixture();
    $db->exec("INSERT INTO household (id, name, user_id) VALUES (60, 'Alice', 2)");

    wallos_household_set_payer_link($db, 1, 51, 2);
    wallos_household_set_payer_link($db, 2, 60, 1);
    assert_true(wallos_household_leave($db, 2)['success'], 'bob leaves');
    assert_same(0, (int) $db->querySingle('SELECT COUNT(*) FROM household WHERE linked_user_id IS NOT NULL'), 'both links are gone');

    assert_true(wallos_household_invite($db, 1, 'bob', 'editor')['success'], 're-invite');
    assert_true(wallos_household_respond($db, 2, wallos_household_membership($db, 1)['id'], true)['success'], 'bob rejoins');
    wallos_household_set_payer_link($db, 1, 51, 2);
    assert_true(wallos_household_delete($db, 1)['success'], 'alice deletes the household');
    assert_same(0, (int) $db->querySingle('SELECT COUNT(*) FROM household WHERE linked_user_id IS NOT NULL'), 'no link survives the household');
    $db->close();
});

wallos_test('the linked payer gets payment reminders on their own log, once each', function () {
    [$db, $id] = household_followups_fixture();
    $settings = ['days' => 1];
    $lookups = wallos_notification_lookups($db, 2);

    [$entries] = wallos_collect_linked_payment_reminders($db, 2, $settings, '2026-11-18', $lookups);
    assert_same([], $entries, 'nothing while the label is not linked');

    wallos_household_set_payer_link($db, 1, 51, 2);
    [$entries, $pending] = wallos_collect_linked_payment_reminders($db, 2, $settings, '2026-11-18', $lookups);
    assert_same(['Netflix'], array_column($entries, 'name'), 'bob is reminded 7 days ahead');
    assert_same('bob', $entries[0]['payer'], 'the linked account names the payer');
    assert_same([], wallos_collect_linked_payment_reminders($db, 5, $settings, '2026-11-18', wallos_notification_lookups($db, 5))[0], 'erin never');

    // The owner's own reminder does not count as bob's, nor bob's as hers.
    $ownerSettings = ['days' => 1];
    [, $ownerPending] = wallos_collect_payment_reminders($db, 1, $ownerSettings, '2026-11-18', wallos_notification_lookups($db, 1));
    wallos_record_payment_reminders($db, 1, $ownerPending, ['payment:' . $id => true]);
    [$entries] = wallos_collect_linked_payment_reminders($db, 2, $settings, '2026-11-18', $lookups);
    assert_same(1, count($entries), "alice's reminder leaves bob's due");

    wallos_record_linked_payment_reminders($db, 2, $pending, ['payment:' . $id => true]);
    [$entries] = wallos_collect_linked_payment_reminders($db, 2, $settings, '2026-11-18', $lookups);
    assert_same([], $entries, 'sent once');
    [$entries] = wallos_collect_payment_reminders($db, 1, $ownerSettings, '2026-11-18', wallos_notification_lookups($db, 1));
    assert_same([], $entries, "and alice's log is her own");

    $contexts = wallos_linked_notification_contexts([['name' => 'x']], ['username' => 'bob', 'email' => 'bob@example.com'], 'payment');
    assert_same('bob', $contexts[0]['payer'], 'the message is addressed to bob');
    $db->close();
});

wallos_test('the linked payer gets cancellation and trial reminders on their own markers', function () {
    [$db, $id] = household_followups_fixture([
        'next_payment' => '2026-12-15', 'cancel_notice_mode' => 'fixed', 'cancellation_date' => '2026-12-01',
        'cancellation_notify_days_before' => 30,
    ]);
    wallos_household_set_payer_link($db, 1, 51, 2);
    $lookups = wallos_notification_lookups($db, 2);

    // alice's reminder is already marked on the row.
    $db->exec("UPDATE subscriptions SET last_cancel_notified_for = 'lead:2026-12-01' WHERE id = $id");
    [$entries, $markers] = wallos_collect_linked_cancellation_reminders($db, 2, '2026-11-18', $lookups);
    assert_same(' (cancel by 2026-12-01)', $entries[0]['deadline_note'] ?? null, 'bob gets the early reminder all the same');
    wallos_record_linked_cancellation_reminders($db, 2, $markers, [wallos_notification_key('cancel', $id) => true]);
    [$entries] = wallos_collect_linked_cancellation_reminders($db, 2, '2026-11-20', $lookups);
    assert_same([], $entries, 'once');
    [$entries, $markers] = wallos_collect_linked_cancellation_reminders($db, 2, '2026-12-01', $lookups);
    assert_same('', $entries[0]['deadline_note'] ?? null, 'and again on the day');
    assert_same('lead:2026-12-01', $db->querySingle("SELECT last_cancel_notified_for FROM subscriptions WHERE id = $id"), "alice's marker is untouched");

    $db->exec("UPDATE subscriptions SET cancel_notice_mode = NULL, cancellation_date = NULL, intro_type = 'trial',
        intro_end_date = '2026-11-20', intro_notify_days = 3, intro_notified_for = '2026-11-20' WHERE id = $id");
    [$entries, $markers] = wallos_collect_linked_cancellation_reminders($db, 2, '2026-11-18', $lookups);
    assert_same(1, count($markers['intro']), "the trial-ending reminder is due for bob though alice's was sent");
    wallos_record_linked_cancellation_reminders($db, 2, $markers, [wallos_notification_key('intro', $id) => true]);
    [, $markers] = wallos_collect_linked_cancellation_reminders($db, 2, '2026-11-19', $lookups);
    assert_same([], $markers['intro'], 'once');
    $db->close();
});
