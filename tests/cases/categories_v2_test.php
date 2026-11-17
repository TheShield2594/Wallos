<?php
/*
  Sub-categories and admin default categories (includes/categories.php, issue #31).
*/

require_once WALLOS_ROOT . '/includes/categories.php';

function categories_v2_db()
{
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 2, 'bob');
    $db->exec("DELETE FROM categories WHERE user_id = 2");
    $db->exec('INSERT INTO categories (id, name, "order", user_id) VALUES (20, \'No category\', 1, 2), (21, \'Computers\', 2, 2), (22, \'Kids\', 3, 2), (23, \'Cloud storage\', 4, 2), (24, \'VPS\', 5, 2)');
    $db->exec('UPDATE categories SET parent_id = 21 WHERE id IN (23, 24)');

    return $db;
}

function categories_v2_rows(SQLite3 $db, $userId)
{
    $rows = [];
    $result = $db->query('SELECT * FROM categories WHERE user_id = ' . (int) $userId . ' ORDER BY "order"');
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[(int) $row['id']] = $row;
    }

    return $rows;
}

wallos_test('the tree lists children under their parent as "Parent › Child"', function () {
    $db = categories_v2_db();
    $tree = wallos_category_tree(categories_v2_rows($db, 2));
    assert_same([20, 21, 23, 24, 22], array_keys($tree), 'children follow the parent');
    assert_same('Computers › VPS', $tree[24]['label'], 'child label');
    assert_same(1, $tree[24]['depth'], 'child depth');
    assert_same('Kids', $tree[22]['label'], 'top-level label');
    $db->close();
});

wallos_test('a parent filter includes its children and costs roll up', function () {
    $db = categories_v2_db();
    $categories = categories_v2_rows($db, 2);
    $expanded = wallos_expand_category_ids($categories, [21]);
    sort($expanded);
    assert_same([21, 23, 24], $expanded, 'parent plus children');
    assert_same([22], wallos_expand_category_ids($categories, [22]), 'no children');
    $rolled = wallos_roll_up_categories([
        21 => ['cost' => 1, 'name' => 'Computers'],
        23 => ['cost' => 5, 'name' => 'Cloud storage'],
        24 => ['cost' => 10, 'name' => 'VPS'],
        22 => ['cost' => 3, 'name' => 'Kids'],
    ], $categories);
    assert_equals(16, $rolled[21]['cost'], 'children add to the parent');
    assert_true(!isset($rolled[23]) && !isset($rolled[24]), 'children are folded in');
    assert_same(21, wallos_category_root_id($categories, 24), 'root of a child');
    $db->close();
});

wallos_test('sub-categories go one level deep and stay within the account', function () {
    $db = categories_v2_db();
    assert_same(null, wallos_category_parent_error($db, 2, 22, 21), 'Kids under Computers is fine');
    assert_true(wallos_category_parent_error($db, 2, 22, 24) !== null, 'no grandchildren');
    assert_true(wallos_category_parent_error($db, 2, 21, 22) !== null, 'a parent cannot become a child');
    assert_true(wallos_category_parent_error($db, 2, 22, 22) !== null, 'not its own parent');
    assert_true(wallos_category_parent_error($db, 2, 22, 20) !== null, 'No category takes no children');
    assert_true(wallos_category_parent_error($db, 1, 22, 21) !== null, 'another account\'s category');
    assert_same(null, wallos_category_parent_error($db, 2, 23, 0), 'top level is always allowed');
    $db->close();
});

wallos_test('new accounts start from the admin defaults, nested, after No category', function () {
    $db = categories_v2_db();
    $db->exec("INSERT INTO category_templates (id, name, parent_template_id, \"order\") VALUES (1, 'Kids', NULL, 1), (2, 'Kid 1', 1, 2), (3, 'Streaming', NULL, 3)");
    wallos_test_create_user($db, 3, 'carol');
    $db->exec("DELETE FROM categories WHERE user_id = 3");
    wallos_seed_user_categories($db, 3, 'en');
    $tree = wallos_category_tree(categories_v2_rows($db, 3));
    $labels = array_values(array_map(function ($row) {
        return $row['label'];
    }, $tree));
    assert_same(['No category', 'Kids', 'Kids › Kid 1', 'Streaming'], $labels, 'seeded from the templates');
    $db->close();
});

wallos_test('without admin defaults new accounts get the built-in list', function () {
    $db = categories_v2_db();
    wallos_test_create_user($db, 3, 'carol');
    $db->exec("DELETE FROM categories WHERE user_id = 3");
    wallos_seed_user_categories($db, 3, 'en');
    assert_same(count(default_categories('en')), count(categories_v2_rows($db, 3)), 'built-in defaults');
    $db->close();
});

wallos_test('applying defaults to everyone adds only what is missing and never renames', function () {
    $db = categories_v2_db();
    $db->exec("INSERT INTO category_templates (id, name, parent_template_id, \"order\") VALUES (1, 'Kids', NULL, 1), (2, 'Kid 1', 1, 2)");
    assert_same(2, wallos_apply_category_templates($db, 2), 'both added');
    $db->exec("UPDATE categories SET name = 'Children' WHERE user_id = 2 AND template_id = 1");
    $db->exec("INSERT INTO category_templates (id, name, parent_template_id, \"order\") VALUES (3, 'Kid 2', 1, 3)");
    assert_same(1, wallos_apply_category_templates($db, 2), 'only the new one');
    $rows = categories_v2_rows($db, 2);
    $names = array_column($rows, 'name');
    assert_true(in_array('Children', $names, true), 'the user\'s rename is kept');
    assert_true(!in_array('Kids', array_slice($names, 5), true), 'no duplicate parent');
    $kid2 = array_values(array_filter($rows, function ($row) {
        return $row['name'] === 'Kid 2';
    }))[0];
    $parent = array_values(array_filter($rows, function ($row) {
        return (int) $row['template_id'] === 1;
    }))[0];
    assert_same((int) $parent['id'], (int) $kid2['parent_id'], 'nested under the user\'s own copy of the parent');
    assert_same(0, wallos_apply_category_templates($db, 2), 'idempotent');
    $db->close();
});

wallos_test('migration 000073 adds parents, template links and the templates table', function () {
    $db = wallos_test_open_database();
    foreach (['parent_id', 'template_id'] as $column) {
        assert_true($db->query("SELECT 1 FROM pragma_table_info('categories') WHERE name = '$column'")->fetchArray() !== false, $column);
    }
    assert_true($db->exec("INSERT INTO category_templates (name) VALUES ('Kids')"), 'templates table');
    $db->close();
});

wallos_test('No category stays at the top level', function () {
    $db = categories_v2_db();
    assert_true(wallos_category_parent_error($db, 2, 20, 22) !== null, 'No category can\'t become a child');
    $db->close();
});

wallos_test('a parent change and a delete each happen whole', function () {
    $db = categories_v2_db();
    $update = wallos_update_category($db, 2, 22, 'Kids', true, 21);
    assert_true($update['success'] && $update['parent_changed'], 'Kids moved under Computers');
    $refused = wallos_update_category($db, 2, 21, 'Computers', true, 22);
    assert_true(!$refused['success'] && $refused['error'] !== null, 'no cycle');
    assert_same(null, $db->querySingle('SELECT parent_id FROM categories WHERE id = 21'), 'Computers untouched');
    assert_true(wallos_delete_category($db, 2, 21), 'delete the parent');
    assert_same(0, $db->querySingle('SELECT COUNT(*) FROM categories WHERE parent_id = 21'), 'children are top-level');
    $db->close();
});

wallos_test('defaults never nest a third level under a category the user moved', function () {
    $db = categories_v2_db();
    $db->exec("INSERT INTO category_templates (id, name, parent_template_id, \"order\") VALUES (1, 'Kids', NULL, 1)");
    wallos_apply_category_templates($db, 2);
    $kidsId = $db->querySingle('SELECT id FROM categories WHERE user_id = 2 AND template_id = 1');
    $db->exec('UPDATE categories SET parent_id = 22 WHERE id = ' . (int) $kidsId);
    $db->exec("INSERT INTO category_templates (id, name, parent_template_id, \"order\") VALUES (2, 'Kid 1', 1, 2)");
    assert_same(1, wallos_apply_category_templates($db, 2), 'added');
    assert_same(null, $db->querySingle('SELECT parent_id FROM categories WHERE user_id = 2 AND template_id = 2'), 'starts at the top level');
    assert_true(!@$db->exec("INSERT INTO categories (name, user_id, template_id) VALUES ('dup', 2, 1)"), 'one category per default per account');
    $db->close();
});
