<?php
/*
  Bundled subscriptions: a parent bill with included children
  (includes/bundles.php, issue #24).
*/

require_once WALLOS_ROOT . '/includes/bundles.php';
require_once WALLOS_ROOT . '/includes/renewal_cron.php';
require_once WALLOS_ROOT . '/includes/upcoming_payments.php';

function bundles_db()
{
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    wallos_test_create_user($db, 2, 'bob');
    $db->exec("DELETE FROM categories WHERE user_id IN (1, 2)");
    $db->exec("INSERT INTO categories (id, name, user_id) VALUES (901, 'Streaming', 1), (902, 'Music', 1), (903, 'Video', 1)");

    return $db;
}

function bundles_subscription(SQLite3 $db, array $fields)
{
    $row = array_merge([
        'user_id' => 1,
        'name' => 'Subscription',
        'price' => 10.0,
        'currency_id' => wallos_test_currency_id((int) ($fields['user_id'] ?? 1), 0),
        'next_payment' => '2030-01-15',
        'cycle' => 3,
        'frequency' => 1,
        'auto_renew' => 1,
        'inactive' => 0,
        'notify' => 1,
        'category_id' => 901,
    ], $fields);
    $columns = array_keys($row);
    $stmt = $db->prepare('INSERT INTO subscriptions (' . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')');
    foreach ($row as $column => $value) {
        $stmt->bindValue(':' . $column, $value, $value === null ? SQLITE3_NULL : (is_float($value) ? SQLITE3_FLOAT : (is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT)));
    }
    $stmt->execute();

    return (int) $db->lastInsertRowID();
}

function bundles_row(SQLite3 $db, $id)
{
    return $db->querySingle('SELECT * FROM subscriptions WHERE id = ' . (int) $id, true);
}

wallos_test('migration 000078 adds the parent link and the allocated amount', function () {
    $db = bundles_db();
    $columns = [];
    $result = $db->query("SELECT name, type, \"notnull\" FROM pragma_table_info('subscriptions') WHERE name IN ('parent_subscription_id', 'allocated_amount')");
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $columns[$row['name']] = $row;
    }
    assert_same('INTEGER', $columns['parent_subscription_id']['type'] ?? null, 'parent_subscription_id');
    assert_same('REAL', $columns['allocated_amount']['type'] ?? null, 'allocated_amount');
    assert_same(0, (int) ($columns['parent_subscription_id']['notnull'] ?? 1), 'nullable');
    $fk = $db->querySingle("SELECT \"table\" FROM pragma_foreign_key_list('subscriptions') WHERE \"from\" = 'parent_subscription_id'");
    assert_same('subscriptions', $fk, 'references subscriptions(id)');
    $id = bundles_subscription($db, ['name' => 'Netflix']);
    assert_same(null, bundles_row($db, $id)['parent_subscription_id'], 'standalone by default');
    // Running it again changes nothing.
    require WALLOS_ROOT . '/migrations/000078.php';
    assert_true(true, 'idempotent');
    $db->close();
});

wallos_test('a parent must be the account\'s own active, recurring, standalone subscription', function () {
    $db = bundles_db();
    $parent = bundles_subscription($db, ['name' => 'Telco bundle', 'price' => 50.0]);
    $child = bundles_subscription($db, ['name' => 'Netflix', 'parent_subscription_id' => $parent]);
    $other = bundles_subscription($db, ['name' => 'Spotify']);
    $disabled = bundles_subscription($db, ['name' => 'Old', 'inactive' => 1]);
    $oneTime = bundles_subscription($db, ['name' => 'Lifetime', 'cycle' => 5]);
    $bobs = bundles_subscription($db, ['name' => 'Bob\'s', 'user_id' => 2]);

    $ok = wallos_bundle_validate_parent($db, 1, $other, $parent);
    assert_same(null, $ok['error'], 'valid parent');
    assert_same($parent, (int) $ok['parent']['id'], 'the parent row');
    assert_same(null, wallos_bundle_validate_parent($db, 1, null, $parent)['error'], 'a new subscription');
    assert_same(null, wallos_bundle_validate_parent($db, 1, $other, '')['error'], 'no parent');
    assert_same(null, wallos_bundle_validate_parent($db, 1, $other, '0')['parent'], 'zero is no parent');
    assert_same('bundle_error_self', wallos_bundle_validate_parent($db, 1, $parent, $parent)['error'], 'not its own parent');
    assert_same('bundle_error_not_found', wallos_bundle_validate_parent($db, 1, $other, $bobs)['error'], 'another account\'s subscription');
    assert_same('bundle_error_not_found', wallos_bundle_validate_parent($db, 1, $other, 99999)['error'], 'missing');
    assert_same('bundle_error_parent_is_child', wallos_bundle_validate_parent($db, 1, $other, $child)['error'], 'one level only');
    assert_same('bundle_error_has_children', wallos_bundle_validate_parent($db, 1, $parent, $other)['error'], 'a parent cannot become a child');
    assert_same('bundle_error_parent_not_active', wallos_bundle_validate_parent($db, 1, $other, $disabled)['error'], 'disabled parent');
    assert_same('bundle_error_parent_not_active', wallos_bundle_validate_parent($db, 1, $other, $oneTime)['error'], 'one-time parent');

    // A child already attached to a parent that was disabled keeps it.
    $db->exec("UPDATE subscriptions SET inactive = 1 WHERE id = $parent");
    assert_same(null, wallos_bundle_validate_parent($db, 1, $child, $parent)['error'], 'kept parent');
    assert_same('bundle_error_parent_not_active', wallos_bundle_validate_parent($db, 1, $other, $parent)['error'], 'no new children');
    $db->close();
});

wallos_test('the save endpoints validate the parent and copy its schedule', function () {
    foreach (['endpoints/subscription/add.php', 'api/subscriptions/set_subscriptions.php'] as $file) {
        $source = file_get_contents(WALLOS_ROOT . '/' . $file);
        assert_contains('wallos_bundle_validate_parent(', $source, $file . ' validates');
        assert_contains('wallos_bundle_inherited_fields(', $source, $file . ' inherits');
        assert_contains('wallos_bundle_sync_children(', $source, $file . ' syncs children');
        assert_contains('wallos_bundle_on_parent_disabled(', $source, $file . ' handles a disabled parent');
        assert_contains('parent_subscription_id = :parentSubscriptionId', $source, $file . ' stores the link');
    }
    $inherited = wallos_bundle_inherited_fields(['cycle' => 4, 'frequency' => 1, 'payment_method_id' => 3, 'payer_user_id' => 7, 'next_payment' => '2030-05-01', 'auto_renew' => 0]);
    assert_same(['cycle' => 4, 'frequency' => 1, 'payment_method_id' => 3, 'payer_user_id' => 7, 'next_payment' => '2030-05-01', 'auto_renew' => 0], $inherited, 'inherited fields');
});

wallos_test('form and API values are sanitized', function () {
    assert_same(null, wallos_sanitize_parent_id(''), 'empty');
    assert_same(null, wallos_sanitize_parent_id('-3'), 'negative');
    assert_same(12, wallos_sanitize_parent_id('12'), 'id');
    assert_same(null, wallos_sanitize_allocated_amount(''), 'no allocation');
    assert_same(null, wallos_sanitize_allocated_amount('-1'), 'negative allocation');
    assert_same(4.5, wallos_sanitize_allocated_amount('4.5'), 'allocation');
    assert_same('detach', wallos_bundle_children_action('detach'), 'detach');
    assert_same('disable', wallos_bundle_children_action('bogus'), 'disable by default');
});

wallos_test('children follow the parent: schedule sync, disable and detach', function () {
    $db = bundles_db();
    $parent = bundles_subscription($db, ['name' => 'Bundle', 'cycle' => 4, 'frequency' => 1, 'payment_method_id' => 5, 'payer_user_id' => 6, 'next_payment' => '2030-03-01']);
    $child = bundles_subscription($db, ['name' => 'Netflix', 'parent_subscription_id' => $parent, 'payment_method_id' => 1, 'next_payment' => '2030-01-15']);
    $bobsChild = bundles_subscription($db, ['name' => 'Bob', 'user_id' => 2, 'parent_subscription_id' => $parent, 'next_payment' => '2030-01-15']);

    assert_true(wallos_bundle_sync_children($db, 1, $parent), 'sync');
    $row = bundles_row($db, $child);
    assert_same(['2030-03-01', 4, 5, 6], [$row['next_payment'], $row['cycle'], $row['payment_method_id'], $row['payer_user_id']], 'child mirrors the parent');
    assert_same('2030-01-15', bundles_row($db, $bobsChild)['next_payment'], 'another account\'s row is untouched');

    assert_true(wallos_bundle_on_parent_disabled($db, 1, $parent, 'disable'), 'disable');
    assert_same(1, bundles_row($db, $child)['inactive'], 'child disabled with the parent');
    assert_same($parent, bundles_row($db, $child)['parent_subscription_id'], 'still attached');

    $db->exec("UPDATE subscriptions SET allocated_amount = 4 WHERE id = $child");
    assert_true(wallos_bundle_on_parent_disabled($db, 1, $parent, 'detach'), 'detach');
    $row = bundles_row($db, $child);
    assert_same([null, null], [$row['parent_subscription_id'], $row['allocated_amount']], 'standalone again');
    $db->close();
});

wallos_test('deleting a parent detaches its children', function () {
    foreach (['endpoints/subscription/delete.php', 'api/subscriptions/set_subscriptions.php'] as $file) {
        assert_contains('UPDATE subscriptions SET parent_subscription_id = NULL, allocated_amount = NULL WHERE parent_subscription_id = ',
            file_get_contents(WALLOS_ROOT . '/' . $file), $file);
    }
});

wallos_test('the renewal cron advances the parent and the children mirror its next payment', function () {
    $db = bundles_db();
    $parent = bundles_subscription($db, ['name' => 'Bundle', 'next_payment' => '2027-01-10']);
    $child = bundles_subscription($db, ['name' => 'Netflix', 'parent_subscription_id' => $parent, 'next_payment' => '2027-01-10']);
    $run = wallos_run_renewals($db, new DateTimeImmutable('2027-01-11 12:00:00'));
    assert_same(1, $run['advanced'], 'only the parent is advanced');
    assert_same('2027-02-10', bundles_row($db, $parent)['next_payment'], 'parent advanced');
    assert_same('2027-02-10', bundles_row($db, $child)['next_payment'], 'child follows');
    assert_same(0, (int) $db->querySingle("SELECT COUNT(*) FROM subscription_payments WHERE subscription_id = $child"), 'no ledger rows for the child');

    // Mark paid and undo go through the same schedule update.
    assert_true(wallos_update_schedule($db, bundles_row($db, $parent), ['next_payment' => '2027-03-10']), 'schedule update');
    assert_same('2027-03-10', bundles_row($db, $child)['next_payment'], 'child follows a manual change');
    assert_true(wallos_update_schedule($db, bundles_row($db, $parent), ['inactive' => 1, 'ended_reason' => 'completed']), 'plan completed');
    assert_same(1, bundles_row($db, $child)['inactive'], 'child ends with its parent');
    $db->close();
});

wallos_test('children are left out of upcoming payments and payment reminders', function () {
    $db = bundles_db();
    $future = (new DateTime('today'))->modify('+3 days')->format('Y-m-d');
    $parent = bundles_subscription($db, ['name' => 'Bundle', 'next_payment' => $future]);
    bundles_subscription($db, ['name' => 'Netflix', 'parent_subscription_id' => $parent, 'next_payment' => $future]);
    $names = array_column(get_upcoming_payments($db, 1, 10), 'name');
    assert_same(['Bundle'], $names, 'only the bill');

    $standalone = 'SELECT COUNT(*) FROM subscriptions WHERE user_id = 1 AND ' . wallos_standalone_sql();
    assert_same(1, (int) $db->querySingle($standalone), 'the helper keeps the parent only');
    assert_same('s.parent_subscription_id IS NULL', wallos_standalone_sql('s'), 'with an alias');
    $db->close();
});

wallos_test('every total uses the standalone filter', function () {
    $files = [
        'includes/stats_calculations.php',
        'api/subscriptions/get_monthly_cost.php',
        'api/subscriptions/get_period_budget.php',
        'api/subscriptions/get_ical_feed.php',
        'endpoints/cronjobs/storetotalyearlycost.php',
        'endpoints/cronjobs/sendnotifications.php',
        'includes/notifications/schedule.php',
        'includes/notifications/preview.php',
        'includes/upcoming_payments.php',
        'includes/renewal_cron.php',
        'index.php',
    ];
    foreach ($files as $file) {
        assert_contains('wallos_standalone_sql(', file_get_contents(WALLOS_ROOT . '/' . $file), $file);
    }
    assert_contains('wallos_is_bundle_child(', file_get_contents(WALLOS_ROOT . '/calendar.php'), 'calendar totals');
    assert_contains('wallos_without_bundle_children(', file_get_contents(WALLOS_ROOT . '/includes/list_subscriptions.php'), 'list footers');
});

wallos_test('the statistics count the parent once and split its category', function () {
    $db = bundles_db();
    $parent = bundles_subscription($db, ['name' => 'Bundle', 'price' => 30.0, 'category_id' => 901]);
    bundles_subscription($db, ['name' => 'Spotify', 'price' => 12.0, 'category_id' => 902, 'parent_subscription_id' => $parent, 'allocated_amount' => 10.0]);
    bundles_subscription($db, ['name' => 'Netflix', 'price' => 15.0, 'category_id' => 903, 'parent_subscription_id' => $parent, 'allocated_amount' => 5.0]);
    bundles_subscription($db, ['name' => 'Standalone', 'price' => 20.0, 'category_id' => 902]);
    $path = $db->querySingle('PRAGMA database_list', true)['file'];
    $db->close();

    // stats_calculations.php is a page include: run it in its own process.
    $script = WALLOS_TEST_TMP . '/bundles-stats-' . uniqid('', true) . '.php';
    file_put_contents($script, '<?php
        error_reporting(E_ERROR | E_PARSE);
        $db = new SQLite3(' . var_export($path, true) . ');
        $userId = 1;
        $userData = ["main_currency" => ' . wallos_test_currency_id(1, 0) . ', "language" => "en", "budget" => 0];
        $settings = [];
        require ' . var_export(WALLOS_ROOT . '/includes/i18n/getlang.php', true) . ';
        require ' . var_export(WALLOS_ROOT . '/includes/i18n/en.php', true) . ';
        require ' . var_export(WALLOS_ROOT . '/includes/stats_calculations.php', true) . ';
        echo json_encode(["total" => $totalCostPerMonth, "active" => $activeSubscriptions,
            "categories" => array_map(fn($c) => round($c["cost"], 4), $categoryCost)]);
    ');
    $output = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1');
    @unlink($script);
    $stats = json_decode((string) $output, true);
    assert_true(is_array($stats), 'stats ran: ' . $output);
    assert_equals(50.0, $stats['total'] ?? null, 'children are not counted twice');
    assert_same(2, $stats['active'] ?? null, 'two bills');
    assert_equals(15.0, $stats['categories'][901] ?? null, 'the remainder stays with the parent');
    assert_equals(30.0, $stats['categories'][902] ?? null, 'Spotify\'s allocation plus the standalone');
    assert_equals(5.0, $stats['categories'][903] ?? null, 'Netflix\'s allocation');
});

wallos_test('allocations split a parent\'s cost between categories', function () {
    $split = wallos_bundle_category_split(30.0, 1, 30.0, [['category_id' => 2, 'allocated_amount' => 10], ['category_id' => 3, 'allocated_amount' => 5]]);
    assert_equals([1 => 15.0, 2 => 10.0, 3 => 5.0], $split, 'per child, remainder to the parent');
    // A yearly 120 bill is 10 a month: the shares scale with it.
    $split = wallos_bundle_category_split(10.0, 1, 120.0, [['category_id' => 2, 'allocated_amount' => 60]]);
    assert_equals([1 => 5.0, 2 => 5.0], $split, 'scaled to the monthly cost');
    // Over-allocated: scaled down to the parent's price.
    $split = wallos_bundle_category_split(20.0, 1, 20.0, [['category_id' => 2, 'allocated_amount' => 30], ['category_id' => 3, 'allocated_amount' => 10]]);
    assert_equals([1 => 0.0, 2 => 15.0, 3 => 5.0], $split, 'never more than the parent');
    assert_equals([1 => 9.0], wallos_bundle_category_split(9.0, 1, 0.0, [['category_id' => 2, 'allocated_amount' => 3]]), 'free parent: no split');
    assert_equals([1 => 9.0], wallos_bundle_category_split(9.0, 1, 9.0, []), 'no children');
    $split = wallos_bundle_category_split(10.0, 1, 10.0, [['category_id' => 1, 'allocated_amount' => 4]]);
    assert_equals([1 => 10.0], $split, 'same category');
});

wallos_test('allocations above the parent price warn', function () {
    $db = bundles_db();
    $parent = bundles_subscription($db, ['name' => 'Bundle', 'price' => 20.0]);
    bundles_subscription($db, ['name' => 'A', 'parent_subscription_id' => $parent, 'allocated_amount' => 15.0]);
    bundles_subscription($db, ['name' => 'B', 'parent_subscription_id' => $parent, 'allocated_amount' => 10.0]);
    bundles_subscription($db, ['name' => 'C', 'parent_subscription_id' => $parent, 'allocated_amount' => 50.0, 'inactive' => 1]);
    assert_equals(25.0, wallos_bundle_allocated_total($db, 1, $parent), 'active children only');
    assert_true(wallos_bundle_allocations_exceed(20.0, 25.0), 'warns');
    assert_true(!wallos_bundle_allocations_exceed(20.0, 20.0), 'exactly the price is fine');
    $byParent = wallos_bundle_allocations_by_parent($db, 1);
    assert_same(2, count($byParent[$parent] ?? []), 'the split uses active children with an allocation');
    $db->close();
});

wallos_test('the list puts children under their parent', function () {
    $rows = [
        ['id' => 3, 'parent_subscription_id' => 1, 'name' => 'Netflix'],
        ['id' => 2, 'parent_subscription_id' => null, 'name' => 'Spotify'],
        ['id' => 1, 'parent_subscription_id' => null, 'name' => 'Bundle'],
        ['id' => 4, 'parent_subscription_id' => 1, 'name' => 'Disney'],
        ['id' => 5, 'parent_subscription_id' => 99, 'name' => 'Orphan in this view'],
    ];
    assert_same([2, 1, 3, 4, 5], array_column(wallos_bundle_nest_rows($rows), 'id'), 'nested');
    assert_same([2, 1], array_column(wallos_without_bundle_children($rows), 'id'), 'standalone rows');

    $db = bundles_db();
    $parent = bundles_subscription($db, ['name' => 'Bundle']);
    bundles_subscription($db, ['name' => 'Netflix', 'parent_subscription_id' => $parent]);
    bundles_subscription($db, ['name' => 'Disney', 'parent_subscription_id' => $parent]);
    $context = wallos_bundle_list_context($db, 1);
    assert_same(2, $context['children'][$parent] ?? 0, '+2 included');
    assert_same('Bundle', $context['names'][$parent] ?? null, 'via Bundle');
    $options = array_column(wallos_bundle_parent_options($db, 1), 'name');
    assert_same(['Bundle'], $options, 'children are not offered as parents');
    $db->close();
});

wallos_test('a clone of a child stays in the same bill', function () {
    $source = file_get_contents(WALLOS_ROOT . '/endpoints/subscription/clone.php');
    assert_contains("'parent_subscription_id', 'allocated_amount'", $source, 'carried over');
});

wallos_test('bundle strings exist in English', function () {
    require WALLOS_ROOT . '/includes/i18n/en.php';
    foreach (['bundle_billed_through', 'bundle_via', 'bundle_n_included', 'bundle_hide_included', 'bundle_allocations_exceed_price',
        'bundle_confirm_disable_children', 'bundle_error_self', 'bundle_error_not_found', 'bundle_error_parent_is_child',
        'bundle_error_parent_not_active', 'bundle_error_has_children'] as $key) {
        assert_true(isset($i18n[$key]), $key);
    }
});
