<?php
/*
  Trial and promo reminders show the intro amount in formatted_price too
  (templates and the webhook read it), not the regular price.
*/

require_once WALLOS_ROOT . '/includes/notifications/schedule.php';
require_once WALLOS_ROOT . '/includes/notifications/messages.php';

wallos_test('an owner promo-ending reminder shows the intro amount in both price fields', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    $eur = wallos_test_currency_id(1, 0);
    $db->exec("INSERT INTO household (id, name, user_id) VALUES (50, 'Alice', 1)");
    $db->exec("INSERT INTO subscriptions (id, user_id, name, price, currency_id, next_payment, cycle, frequency, inactive, notify,
            auto_renew, category_id, payer_user_id, payment_method_id, start_date, intro_type, intro_price, intro_end_date, intro_notify_days)
        VALUES (60, 1, 'Promo', 10, $eur, '2026-11-20', 3, 1, 0, 1, 1, 1, 50, 1, '2026-10-20', 'promo', 2.5, '2026-11-20', 3)");

    [$entries, $markers] = wallos_collect_cancellation_reminders($db, 1, '2026-11-18', wallos_notification_lookups($db, 1));
    assert_same([60], array_keys($markers['intro']), 'the promo-ending reminder is due');
    $entry = $entries[50][0];
    assert_contains('2.5', $entry['price'], 'price is the intro amount');
    assert_contains('2.5', $entry['formatted_price'], 'formatted_price is the intro amount too');
    assert_not_contains('10', $entry['formatted_price'], 'not the regular price');
    $db->close();
});
