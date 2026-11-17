<?php
/*
  Category model v2 (issue #31).

  Sub-categories
    categories.parent_id points to a top-level category of the same account.
    Depth is capped at two: a parent cannot have a parent, and a category
    with children cannot become a child. The subscription form shows
    "Parent › Child", the statistics and the dashboard category card roll
    children up into their parent, and a ?category= filter on a parent
    includes its children.

  Default categories (admin)
    category_templates holds the categories new accounts start with
    (parent_template_id nests them). Once the admin has defined any, they
    replace the built-in defaults. "Apply to all users" adds the templates an
    account is missing, matched on categories.template_id; it never renames
    or deletes an account's own categories.
*/

require_once __DIR__ . '/default_names.php';

const WALLOS_CATEGORY_SEPARATOR = ' › ';

/**
 * Categories in display order: each top-level category followed by its
 * children. Each row gains 'depth' (0 or 1) and 'label' ("Parent › Child").
 *
 * @param array $categories id => row (with name, parent_id, order)
 * @return array id => row
 */
function wallos_category_tree(array $categories)
{
    $children = [];
    $roots = [];
    foreach ($categories as $id => $category) {
        $parentId = (int) ($category['parent_id'] ?? 0);
        if ($parentId > 0 && isset($categories[$parentId]) && (int) ($categories[$parentId]['parent_id'] ?? 0) === 0 && $parentId !== (int) $id) {
            $children[$parentId][$id] = $category;
        } else {
            $roots[$id] = $category;
        }
    }

    $tree = [];
    foreach ($roots as $id => $category) {
        $category['depth'] = 0;
        $category['label'] = $category['name'];
        $tree[$id] = $category;
        foreach ($children[$id] ?? [] as $childId => $child) {
            $child['depth'] = 1;
            $child['label'] = $category['name'] . WALLOS_CATEGORY_SEPARATOR . $child['name'];
            $tree[$childId] = $child;
        }
    }

    return $tree;
}

/**
 * The top-level category a category rolls up into (itself when top-level).
 *
 * @return int
 */
function wallos_category_root_id(array $categories, $categoryId)
{
    $categoryId = (int) $categoryId;
    $parentId = (int) ($categories[$categoryId]['parent_id'] ?? 0);

    return $parentId > 0 && isset($categories[$parentId]) ? $parentId : $categoryId;
}

/**
 * Category ids plus the children of any parent among them.
 *
 * @param int[] $categoryIds
 * @return int[]
 */
function wallos_expand_category_ids(array $categories, array $categoryIds)
{
    $expanded = array_map('intval', $categoryIds);
    foreach ($categories as $id => $category) {
        if (in_array((int) ($category['parent_id'] ?? 0), $expanded, true)) {
            $expanded[] = (int) $id;
        }
    }

    return array_values(array_unique($expanded));
}

/**
 * Adds children's amounts to their parent's: id => ['cost' => ...] rows.
 *
 * @param array  $costs      category id => row with $field
 * @param array  $categories id => row with parent_id
 * @param string $field
 * @return array
 */
function wallos_roll_up_categories(array $costs, array $categories, $field = 'cost')
{
    foreach ($costs as $id => $row) {
        $rootId = wallos_category_root_id($categories, $id);
        if ($rootId === (int) $id || !isset($costs[$rootId])) {
            continue;
        }
        $costs[$rootId][$field] = ($costs[$rootId][$field] ?? 0) + ($row[$field] ?? 0);
        if (isset($row['count'], $costs[$rootId]['count'])) {
            $costs[$rootId]['count'] += $row['count'];
        }
        unset($costs[$id]);
    }

    return $costs;
}

/**
 * Whether $parentId can be the parent of $categoryId (both of the account).
 *
 * @return string|null an error message, or null when allowed
 */
function wallos_category_parent_error(SQLite3 $db, $userId, $categoryId, $parentId)
{
    $categoryId = (int) $categoryId;
    $parentId = (int) $parentId;
    if ($parentId === 0) {
        return null;
    }
    if ($parentId === $categoryId) {
        return 'A category cannot be its own parent.';
    }
    $stmt = $db->prepare('SELECT id, parent_id FROM categories WHERE id = :id AND user_id = :userId');
    $stmt->bindValue(':id', $parentId, SQLITE3_INTEGER);
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
    $parent = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if ($parent === false) {
        return 'Unknown parent category.';
    }
    if ((int) ($parent['parent_id'] ?? 0) > 0) {
        return 'Sub-categories go one level deep.';
    }
    if ($parentId === wallos_no_category_id($db, $userId)) {
        return 'No category cannot have sub-categories.';
    }
    $stmt = $db->prepare('SELECT COUNT(*) FROM categories WHERE parent_id = :id AND user_id = :userId');
    $stmt->bindValue(':id', $categoryId, SQLITE3_INTEGER);
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
    if ((int) $stmt->execute()->fetchArray(SQLITE3_NUM)[0] > 0) {
        return 'A category with sub-categories cannot become one.';
    }

    return null;
}

/**
 * The account's "No category": its first category.
 *
 * @return int|null
 */
function wallos_no_category_id(SQLite3 $db, $userId)
{
    $stmt = $db->prepare('SELECT MIN(id) FROM categories WHERE user_id = :userId');
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
    $row = $stmt->execute()->fetchArray(SQLITE3_NUM);

    return $row && $row[0] !== null ? (int) $row[0] : null;
}

/**
 * The admin's default categories, parents before their children.
 *
 * @return array[] rows with id, name, parent_template_id, order
 */
function wallos_category_templates(SQLite3 $db)
{
    $rows = [];
    $result = @$db->query('SELECT id, name, parent_template_id, "order" FROM category_templates ORDER BY "order", id');
    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $rows[(int) $row['id']] = $row;
    }
    $ordered = [];
    foreach ($rows as $id => $row) {
        if ((int) ($row['parent_template_id'] ?? 0) === 0 || !isset($rows[(int) $row['parent_template_id']])) {
            $ordered[$id] = $row;
            foreach ($rows as $childId => $child) {
                if ((int) ($child['parent_template_id'] ?? 0) === $id) {
                    $ordered[$childId] = $child;
                }
            }
        }
    }

    return $ordered;
}

/**
 * Adds the templates an account does not have yet (matched on template_id),
 * after its own categories. Never renames or deletes anything.
 *
 * @return int the number of categories added
 */
function wallos_apply_category_templates(SQLite3 $db, $userId, array $templates = null)
{
    $templates = $templates ?? wallos_category_templates($db);
    if ($templates === []) {
        return 0;
    }
    $userId = (int) $userId;

    $existing = [];
    $stmt = $db->prepare('SELECT id, template_id FROM categories WHERE user_id = :userId AND template_id IS NOT NULL');
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $existing[(int) $row['template_id']] = (int) $row['id'];
    }
    $stmt = $db->prepare('SELECT COALESCE(MAX("order"), 0) FROM categories WHERE user_id = :userId');
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $order = (int) $stmt->execute()->fetchArray(SQLITE3_NUM)[0];

    $added = 0;
    $insert = $db->prepare('INSERT INTO categories (name, "order", user_id, parent_id, template_id) VALUES (:name, :order, :userId, :parentId, :templateId)');
    foreach ($templates as $templateId => $template) {
        if (isset($existing[$templateId])) {
            continue;
        }
        $parentTemplateId = (int) ($template['parent_template_id'] ?? 0);
        $parentId = $parentTemplateId > 0 ? ($existing[$parentTemplateId] ?? null) : null;
        $insert->reset();
        $insert->bindValue(':name', $template['name'], SQLITE3_TEXT);
        $insert->bindValue(':order', ++$order, SQLITE3_INTEGER);
        $insert->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $insert->bindValue(':parentId', $parentId, $parentId === null ? SQLITE3_NULL : SQLITE3_INTEGER);
        $insert->bindValue(':templateId', (int) $templateId, SQLITE3_INTEGER);
        if ($insert->execute() === false) {
            return $added;
        }
        $existing[$templateId] = $db->lastInsertRowID();
        $added++;
    }

    return $added;
}

/**
 * The categories a new account starts with: "No category", then the admin's
 * default categories, or the built-in ones in the account's language when
 * the admin has not defined any.
 */
function wallos_seed_user_categories(SQLite3 $db, $userId, $language)
{
    $defaults = default_categories($language);
    $templates = wallos_category_templates($db);
    $names = $templates === [] ? $defaults : [$defaults[0]];

    $stmt = $db->prepare('INSERT INTO categories (name, "order", user_id) VALUES (:name, :order, :userId)');
    foreach ($names as $index => $name) {
        $stmt->reset();
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->bindValue(':order', $index + 1, SQLITE3_INTEGER);
        $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
        $stmt->execute();
    }

    if ($templates !== []) {
        wallos_apply_category_templates($db, $userId, $templates);
    }
}
