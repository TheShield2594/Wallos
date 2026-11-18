<?php
/*
  Bundled subscriptions (issue #24): a parent bill with included children.

  subscriptions.parent_subscription_id   the bill this subscription is
                                         included in ("Billed through").
  subscriptions.allocated_amount         optional part of the parent's price
                                         attributed to this child, in the
                                         parent's currency, per parent cycle.

  Rules:
  - One level only: a parent is never itself a child, and a subscription
    with children cannot become a child. A subscription is never its own
    parent. So no cycles can form.
  - The parent is one of the account's own active, recurring subscriptions.
  - A child inherits the parent's cycle, frequency, payment method, payer and
    next payment, and follows them whenever the parent's schedule changes
    (wallos_bundle_sync_children(), called from the save paths and from
    wallos_update_schedule() for the renewal cron, mark paid and undo).
  - A child keeps its own price as its standalone price, but it is never
    counted in totals: the parent is the bill. Every aggregate filters with
    wallos_standalone_sql() (SQL) or wallos_without_bundle_children() (rows).
  - The category split is always on: in the statistics, each child's
    allocated amount moves from the parent's category to the child's, and
    the remainder stays with the parent. Allocations that add up to more
    than the parent's price are scaled down to it (the save only warns).
  - Deleting a parent detaches its children; disabling a parent disables its
    children, or detaches them when the user picks that.
*/

/**
 * The SQL condition that keeps only subscriptions that are not included in
 * another one: the rows every total, budget and reminder counts.
 *
 * @param string|null $alias table alias, e.g. 's'
 * @return string
 */
function wallos_standalone_sql($alias = null)
{
    return ($alias !== null && $alias !== '' ? $alias . '.' : '') . 'parent_subscription_id IS NULL';
}

/**
 * Whether a row is included in a parent bill.
 */
function wallos_is_bundle_child(array $row)
{
    return isset($row['parent_subscription_id']) && $row['parent_subscription_id'] !== '' && (int) $row['parent_subscription_id'] > 0;
}

/**
 * The rows that are not included in a parent bill.
 *
 * @param array[] $rows
 * @return array[]
 */
function wallos_without_bundle_children(array $rows)
{
    return array_values(array_filter($rows, fn($row) => !wallos_is_bundle_child($row)));
}

/**
 * A parent id from a form or API value: a positive integer, or null.
 *
 * @return int|null
 */
function wallos_sanitize_parent_id($value)
{
    if ($value === null || $value === '' || !is_numeric($value)) {
        return null;
    }
    $id = (int) $value;

    return $id > 0 ? $id : null;
}

/**
 * An allocated amount from a form or API value: a non-negative number, or
 * null for none.
 *
 * @return float|null
 */
function wallos_sanitize_allocated_amount($value)
{
    if ($value === null || $value === '' || !is_numeric($value)) {
        return null;
    }
    $amount = round((float) $value, 4);

    return $amount >= 0 ? $amount : null;
}

/**
 * How many children each of the account's subscriptions has.
 *
 * @return array<int,int> parent id => count
 */
function wallos_bundle_child_counts(SQLite3 $db, $userId)
{
    $counts = [];
    $stmt = $db->prepare('SELECT parent_subscription_id, COUNT(*) AS n FROM subscriptions
        WHERE user_id = :userId AND parent_subscription_id IS NOT NULL GROUP BY parent_subscription_id');
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $counts[(int) $row['parent_subscription_id']] = (int) $row['n'];
    }

    return $counts;
}

/**
 * Checks a "Billed through" choice.
 *
 * @param int|null $subscriptionId the subscription being saved (null when new)
 * @param int|null $parentId       the chosen parent (null for none)
 * @return array{error: string|null, parent: array|null} error is an i18n key
 */
function wallos_bundle_validate_parent(SQLite3 $db, $userId, $subscriptionId, $parentId)
{
    $parentId = wallos_sanitize_parent_id($parentId);
    if ($parentId === null) {
        return ['error' => null, 'parent' => null];
    }
    $subscriptionId = wallos_sanitize_parent_id($subscriptionId);
    if ($subscriptionId !== null && $subscriptionId === $parentId) {
        return ['error' => 'bundle_error_self', 'parent' => null];
    }

    $stmt = $db->prepare('SELECT * FROM subscriptions WHERE id = :id AND user_id = :userId');
    $stmt->bindValue(':id', $parentId, SQLITE3_INTEGER);
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $parent = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
    if ($parent === false) {
        return ['error' => 'bundle_error_not_found', 'parent' => null];
    }
    if (wallos_is_bundle_child($parent)) {
        return ['error' => 'bundle_error_parent_is_child', 'parent' => null];
    }
    // A disabled parent is kept by children that are already attached to it
    // (disabling a parent disables its children); it cannot gain new ones.
    $currentParentId = null;
    if ($subscriptionId !== null) {
        $currentStmt = $db->prepare('SELECT parent_subscription_id FROM subscriptions WHERE id = :id AND user_id = :userId');
        $currentStmt->bindValue(':id', $subscriptionId, SQLITE3_INTEGER);
        $currentStmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
        $currentResult = $currentStmt->execute();
        $currentRow = $currentResult ? $currentResult->fetchArray(SQLITE3_ASSOC) : false;
        $currentParentId = $currentRow ? wallos_sanitize_parent_id($currentRow['parent_subscription_id']) : null;
    }
    if ((int) $parent['cycle'] === 5 || ((int) $parent['inactive'] === 1 && $currentParentId !== $parentId)) {
        return ['error' => 'bundle_error_parent_not_active', 'parent' => null];
    }
    if ($subscriptionId !== null && (wallos_bundle_child_counts($db, $userId)[$subscriptionId] ?? 0) > 0) {
        return ['error' => 'bundle_error_has_children', 'parent' => null];
    }

    return ['error' => null, 'parent' => $parent];
}

/**
 * The fields a child takes from its parent.
 *
 * @return array cycle, frequency, payment_method_id, payer_user_id, next_payment, auto_renew
 */
function wallos_bundle_inherited_fields(array $parent)
{
    return [
        'cycle' => (int) $parent['cycle'],
        'frequency' => (int) $parent['frequency'],
        'payment_method_id' => $parent['payment_method_id'],
        'payer_user_id' => $parent['payer_user_id'],
        'next_payment' => $parent['next_payment'],
        'auto_renew' => (int) ($parent['auto_renew'] ?? 1),
    ];
}

/**
 * Copies the parent's schedule, payment method and payer to its children.
 *
 * @return bool
 */
function wallos_bundle_sync_children(SQLite3 $db, $userId, $parentId)
{
    // Correlated sub-selects rather than UPDATE ... FROM, which needs SQLite 3.33.
    $columns = ['cycle', 'frequency', 'payment_method_id', 'payer_user_id', 'next_payment', 'auto_renew'];
    $sets = array_map(fn($column) => $column . ' = (SELECT p.' . $column . ' FROM subscriptions p WHERE p.id = :parentId AND p.user_id = :userId)', $columns);
    $stmt = $db->prepare('UPDATE subscriptions SET ' . implode(', ', $sets) . '
        WHERE parent_subscription_id = :parentId AND user_id = :userId
          AND EXISTS (SELECT 1 FROM subscriptions p WHERE p.id = :parentId AND p.user_id = :userId)');
    if ($stmt === false) {
        return false;
    }
    $stmt->bindValue(':parentId', (int) $parentId, SQLITE3_INTEGER);
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);

    return $stmt->execute() !== false;
}

/**
 * Only the next payment of the children follows the parent (the renewal
 * cron, mark paid and undo move nothing else).
 *
 * @return bool
 */
function wallos_bundle_sync_children_next_payment(SQLite3 $db, $userId, $parentId, $nextPayment)
{
    $stmt = $db->prepare('UPDATE subscriptions SET next_payment = :nextPayment
        WHERE parent_subscription_id = :parentId AND user_id = :userId');
    if ($stmt === false) {
        return false;
    }
    $stmt->bindValue(':nextPayment', $nextPayment, $nextPayment === null ? SQLITE3_NULL : SQLITE3_TEXT);
    $stmt->bindValue(':parentId', (int) $parentId, SQLITE3_INTEGER);
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);

    return $stmt->execute() !== false;
}

/**
 * Detaches the children of a parent: they become standalone subscriptions
 * again and count in the totals.
 *
 * @return bool
 */
function wallos_bundle_detach_children(SQLite3 $db, $userId, $parentId)
{
    $stmt = $db->prepare('UPDATE subscriptions SET parent_subscription_id = NULL, allocated_amount = NULL
        WHERE parent_subscription_id = :parentId AND user_id = :userId');
    if ($stmt === false) {
        return false;
    }
    $stmt->bindValue(':parentId', (int) $parentId, SQLITE3_INTEGER);
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);

    return $stmt->execute() !== false;
}

/**
 * Disables the children of a parent (they stay attached).
 *
 * @return bool
 */
function wallos_bundle_disable_children(SQLite3 $db, $userId, $parentId)
{
    $stmt = $db->prepare('UPDATE subscriptions SET inactive = 1
        WHERE parent_subscription_id = :parentId AND user_id = :userId');
    if ($stmt === false) {
        return false;
    }
    $stmt->bindValue(':parentId', (int) $parentId, SQLITE3_INTEGER);
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);

    return $stmt->execute() !== false;
}

/**
 * What happens to the children when their parent is disabled.
 *
 * @return string 'disable' or 'detach'
 */
function wallos_bundle_children_action($value)
{
    return $value === 'detach' ? 'detach' : 'disable';
}

/**
 * Applies the children action for a parent that is now disabled.
 *
 * @return bool
 */
function wallos_bundle_on_parent_disabled(SQLite3 $db, $userId, $parentId, $action)
{
    return wallos_bundle_children_action($action) === 'detach'
        ? wallos_bundle_detach_children($db, $userId, $parentId)
        : wallos_bundle_disable_children($db, $userId, $parentId);
}

/**
 * The account's subscriptions that can be picked as a parent: active,
 * recurring, and not themselves included in another one.
 *
 * @return array[] id, name
 */
function wallos_bundle_parent_options(SQLite3 $db, $userId)
{
    $options = [];
    $stmt = $db->prepare('SELECT id, name FROM subscriptions WHERE user_id = :userId AND inactive = 0 AND cycle != 5
        AND ' . wallos_standalone_sql() . ' ORDER BY name COLLATE NOCASE ASC');
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $options[] = ['id' => (int) $row['id'], 'name' => $row['name']];
    }

    return $options;
}

/**
 * The sum of the children's allocated amounts of a parent.
 *
 * @return float
 */
function wallos_bundle_allocated_total(SQLite3 $db, $userId, $parentId)
{
    $stmt = $db->prepare('SELECT COALESCE(SUM(allocated_amount), 0) FROM subscriptions
        WHERE parent_subscription_id = :parentId AND user_id = :userId AND inactive = 0');
    $stmt->bindValue(':parentId', (int) $parentId, SQLITE3_INTEGER);
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result ? $result->fetchArray(SQLITE3_NUM) : false;

    return $row ? (float) $row[0] : 0.0;
}

/**
 * Whether the allocations of a parent add up to more than its price (the
 * save warns, it does not refuse).
 */
function wallos_bundle_allocations_exceed($parentPrice, $allocatedTotal)
{
    return round((float) $allocatedTotal, 4) > round((float) $parentPrice, 4);
}

/**
 * The account's active children with an allocation, by parent.
 *
 * @return array<int,array[]> parent id => [['id', 'category_id', 'allocated_amount']]
 */
function wallos_bundle_allocations_by_parent(SQLite3 $db, $userId)
{
    $byParent = [];
    $stmt = $db->prepare('SELECT id, parent_subscription_id, category_id, allocated_amount FROM subscriptions
        WHERE user_id = :userId AND parent_subscription_id IS NOT NULL AND inactive = 0
          AND allocated_amount IS NOT NULL AND allocated_amount > 0');
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $byParent[(int) $row['parent_subscription_id']][] = [
            'id' => (int) $row['id'],
            'category_id' => $row['category_id'],
            'allocated_amount' => (float) $row['allocated_amount'],
        ];
    }

    return $byParent;
}

/**
 * Splits a parent's cost between categories: each child's allocated amount
 * goes to the child's category, the remainder stays with the parent's.
 * Allocations above the parent's price are scaled down to it.
 *
 * @param float      $amount           the parent's cost to split (e.g. per month, main currency)
 * @param int|string $parentCategoryId
 * @param float      $parentPrice      the parent's price per cycle, in its currency
 * @param array[]    $children         category_id, allocated_amount (parent currency, per cycle)
 * @return array<int|string,float> category id => amount
 */
function wallos_bundle_category_split($amount, $parentCategoryId, $parentPrice, array $children)
{
    $amount = (float) $amount;
    $parentPrice = (float) $parentPrice;
    $split = [$parentCategoryId => $amount];
    if ($parentPrice <= 0 || $children === []) {
        return $split;
    }
    $allocated = 0.0;
    foreach ($children as $child) {
        $allocated += max(0, (float) ($child['allocated_amount'] ?? 0));
    }
    if ($allocated <= 0) {
        return $split;
    }
    $scale = $allocated > $parentPrice ? $parentPrice / $allocated : 1.0;
    foreach ($children as $child) {
        $share = $amount * max(0, (float) ($child['allocated_amount'] ?? 0)) * $scale / $parentPrice;
        $categoryId = $child['category_id'] ?? $parentCategoryId;
        $split[$parentCategoryId] -= $share;
        $split[$categoryId] = ($split[$categoryId] ?? 0) + $share;
    }
    $split[$parentCategoryId] = max(0, round($split[$parentCategoryId], 10));

    return $split;
}

/**
 * Names and child counts of the account's subscriptions, for the list rows
 * ("+2 included", "via Netflix Premium"). Cached per request.
 *
 * @return array{names: array<int,string>, children: array<int,int>}
 */
function wallos_bundle_list_context(SQLite3 $db, $userId)
{
    static $cache = [];
    $key = spl_object_id($db) . '-' . (int) $userId;
    if (!isset($cache[$key])) {
        $names = [];
        $stmt = $db->prepare('SELECT id, name FROM subscriptions WHERE user_id = :userId AND id IN
            (SELECT parent_subscription_id FROM subscriptions WHERE user_id = :userId AND parent_subscription_id IS NOT NULL)');
        $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
            $names[(int) $row['id']] = $row['name'];
        }
        $cache[$key] = ['names' => $names, 'children' => wallos_bundle_child_counts($db, $userId)];
    }

    return $cache[$key];
}

/**
 * Orders list rows so each child follows its parent when both are in the
 * list; a child whose parent is not shown keeps its place.
 *
 * @param array[] $rows rows with 'id' and 'parent_subscription_id'
 * @return array[]
 */
function wallos_bundle_nest_rows(array $rows)
{
    $present = [];
    foreach ($rows as $row) {
        $present[(int) $row['id']] = true;
    }
    $childrenOf = [];
    foreach ($rows as $row) {
        if (wallos_is_bundle_child($row) && isset($present[(int) $row['parent_subscription_id']])) {
            $childrenOf[(int) $row['parent_subscription_id']][] = $row;
        }
    }
    $nested = [];
    foreach ($rows as $row) {
        if (wallos_is_bundle_child($row) && isset($present[(int) $row['parent_subscription_id']])) {
            continue;
        }
        $nested[] = $row;
        foreach ($childrenOf[(int) $row['id']] ?? [] as $child) {
            $nested[] = $child;
        }
    }

    return $nested;
}
