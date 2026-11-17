<?php
/*
  Cancellation deadlines in the list and on the dashboard (issue #30).
*/

require_once WALLOS_ROOT . '/includes/cancellation_deadline.php';

wallos_test('cancel-by dates are grouped this month, next month and later', function () {
    assert_same('this_month', wallos_cancel_by_bucket('2026-03-31', '2026-03-20'), 'end of this month');
    assert_same('next_month', wallos_cancel_by_bucket('2026-04-01', '2026-03-20'), 'start of next month');
    assert_same('later', wallos_cancel_by_bucket('2026-05-01', '2026-03-20'), 'the month after');
    assert_same('next_month', wallos_cancel_by_bucket('2027-01-15', '2026-12-20'), 'across the year');
    assert_same('none', wallos_cancel_by_bucket(null, '2026-03-20'), 'no date');
});

wallos_test('the dashboard window ends with next month', function () {
    assert_same('2026-04-30', wallos_cancel_window_end('2026-03-20'), 'end of April');
    assert_same('2027-01-31', wallos_cancel_window_end('2026-12-31'), 'across the year');
    assert_same('2026-02-28', wallos_cancel_window_end('2026-01-31'), 'a short month');
});

wallos_test('sorting by cancel-by date puts rows without one last, in their order', function () {
    $rows = [
        ['name' => 'A', 'cancel_by' => null],
        ['name' => 'B', 'cancel_by' => '2026-05-01'],
        ['name' => 'C', 'cancel_by' => '2026-04-01'],
        ['name' => 'D', 'cancel_by' => null],
        ['name' => 'E', 'cancel_by' => '2026-04-01'],
    ];
    assert_same(['C', 'E', 'B', 'A', 'D'], array_column(wallos_sort_by_cancel_by($rows), 'name'), 'soonest first, stable');
    assert_true(!array_key_exists('__position', wallos_sort_by_cancel_by($rows)[0]), 'no helper field left behind');
});

wallos_test('the notification filter reads the query and matches shared rows like SQL', function () {
    assert_same(['cancellation', 'reminder'], wallos_notification_filter_types('cancellation,bogus,reminder,cancellation'), 'unknown and repeated types dropped');
    assert_same([], wallos_notification_filter_types(null), 'no filter');
    assert_same(null, wallos_notification_filter_condition('bogus'), 'no condition for an unknown type');

    $flagged = ['cycle' => 3, 'notify' => 0, 'cancel_notice_mode' => 'fixed', 'cancellation_date' => '2026-12-01'];
    $oneTime = ['cycle' => 5, 'notify' => 0, 'cancel_notice_mode' => 'fixed', 'cancellation_date' => '2026-12-01'];
    $reminder = ['cycle' => 3, 'notify' => 1, 'cancel_notice_mode' => null, 'cancellation_date' => null];
    assert_true(wallos_notification_filter_matches($flagged, ['cancellation']), 'flagged recurring');
    assert_true(!wallos_notification_filter_matches($oneTime, ['cancellation']), 'one-time is never cancellable');
    assert_true(wallos_notification_filter_matches($oneTime, ['none']), 'one-time falls under none');
    assert_true(wallos_notification_filter_matches($reminder, ['reminder', 'cancellation']), 'any type matches');
    assert_true(wallos_notification_filter_matches($reminder, []), 'no filter keeps everything');
});

wallos_test('the list offers the cancel-by sort and the dashboard links to it', function () {
    assert_contains("'cancellation_date' =>", file_get_contents(WALLOS_ROOT . '/includes/sort_options.php'), 'sort menu option');
    assert_contains("'cancellation_date']", file_get_contents(WALLOS_ROOT . '/subscriptions.php'), 'allowed on the page');
    assert_contains("'cancellation_date']", file_get_contents(WALLOS_ROOT . '/endpoints/subscriptions/get.php'), 'allowed on the endpoint');
    assert_contains('notifications=cancellation&amp;sort=cancellation_date', file_get_contents(WALLOS_ROOT . '/index.php'), 'dashboard View all');
});
