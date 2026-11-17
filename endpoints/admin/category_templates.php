<?php
/*
  Admin > Default categories (see includes/categories.php, issue #31).

  POST JSON {"action": "add", "name": "...", "parent_id": 3|null}
            {"action": "edit", "id": 4, "name": "...", "parent_id": 3|null}
            {"action": "delete", "id": 4}
            {"action": "apply"}   adds the missing defaults to every account
*/
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint_admin.php';
require_once '../../includes/inputvalidation.php';
require_once '../../includes/categories.php';

$data = json_decode(file_get_contents('php://input'), true);
$action = is_array($data) ? ($data['action'] ?? '') : '';

function category_template_reply($success, $message, array $extra = [])
{
    die(json_encode(array_merge(['success' => $success, 'message' => $message], $extra)));
}

/**
 * A valid parent for a template: an existing top-level template other than itself.
 */
function category_template_parent(SQLite3 $db, $parentId, $templateId = null)
{
    if ($parentId === null || $parentId === '' || (int) $parentId <= 0) {
        return [null, null];
    }
    $parentId = (int) $parentId;
    if ($templateId !== null && $parentId === (int) $templateId) {
        return [null, 'A category cannot be its own parent.'];
    }
    $stmt = $db->prepare('SELECT parent_template_id FROM category_templates WHERE id = :id');
    $stmt->bindValue(':id', $parentId, SQLITE3_INTEGER);
    $parent = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if ($parent === false) {
        return [null, 'Unknown parent category.'];
    }
    if ((int) ($parent['parent_template_id'] ?? 0) > 0) {
        return [null, 'Sub-categories go one level deep.'];
    }
    if ($templateId !== null) {
        $stmt = $db->prepare('SELECT COUNT(*) FROM category_templates WHERE parent_template_id = :id');
        $stmt->bindValue(':id', (int) $templateId, SQLITE3_INTEGER);
        if ((int) $stmt->execute()->fetchArray(SQLITE3_NUM)[0] > 0) {
            return [null, 'A category with sub-categories cannot become one.'];
        }
    }

    return [$parentId, null];
}

switch ($action) {
    case 'add':
    case 'edit':
        $name = trim(validate((string) ($data['name'] ?? '')));
        if ($name === '' || mb_strlen($name) > 100) {
            category_template_reply(false, translate('fill_all_fields', $i18n));
        }
        $templateId = $action === 'edit' ? (int) ($data['id'] ?? 0) : null;
        [$parentId, $error] = category_template_parent($db, $data['parent_id'] ?? null, $templateId);
        if ($error !== null) {
            category_template_reply(false, $error);
        }
        if ($action === 'add') {
            $order = (int) $db->querySingle('SELECT COALESCE(MAX("order"), 0) FROM category_templates') + 1;
            $stmt = $db->prepare('INSERT INTO category_templates (name, parent_template_id, "order") VALUES (:name, :parent, :order)');
            $stmt->bindValue(':order', $order, SQLITE3_INTEGER);
        } else {
            $stmt = $db->prepare('UPDATE category_templates SET name = :name, parent_template_id = :parent WHERE id = :id');
            $stmt->bindValue(':id', $templateId, SQLITE3_INTEGER);
        }
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->bindValue(':parent', $parentId, $parentId === null ? SQLITE3_NULL : SQLITE3_INTEGER);
        if ($stmt->execute() === false) {
            category_template_reply(false, translate('error', $i18n));
        }
        category_template_reply(true, translate('success', $i18n));
        break;

    case 'delete':
        $templateId = (int) ($data['id'] ?? 0);
        // Accounts keep the categories made from it; its sub-categories become top-level.
        $ok = $db->exec('BEGIN IMMEDIATE') !== false;
        foreach (['UPDATE category_templates SET parent_template_id = NULL WHERE parent_template_id = :id',
            'UPDATE categories SET template_id = NULL WHERE template_id = :id',
            'DELETE FROM category_templates WHERE id = :id'] as $sql) {
            if (!$ok) {
                break;
            }
            $stmt = $db->prepare($sql);
            $stmt->bindValue(':id', $templateId, SQLITE3_INTEGER);
            $ok = $stmt->execute() !== false;
        }
        $ok = $ok && $db->exec('COMMIT') !== false;
        if (!$ok) {
            @$db->exec('ROLLBACK');
            category_template_reply(false, translate('error', $i18n));
        }
        category_template_reply(true, translate('success', $i18n));
        break;

    case 'apply':
        $templates = wallos_category_templates($db);
        $added = 0;
        $userIds = [];
        $users = $db->query('SELECT id FROM user');
        while ($users && ($user = $users->fetchArray(SQLITE3_NUM))) {
            $userIds[] = (int) $user[0];
        }
        $ok = $db->exec('BEGIN IMMEDIATE') !== false;
        foreach ($userIds as $applyUserId) {
            if (!$ok) {
                break;
            }
            $addedForUser = wallos_apply_category_templates($db, $applyUserId, $templates);
            if ($addedForUser === false) {
                $ok = false;
                break;
            }
            $added += $addedForUser;
        }
        $ok = $ok && $db->exec('COMMIT') !== false;
        if (!$ok) {
            @$db->exec('ROLLBACK');
            category_template_reply(false, translate('error', $i18n));
        }
        category_template_reply(true, sprintf(translate('default_categories_applied', $i18n), $added), ['added' => $added]);
        break;
}

category_template_reply(false, translate('error', $i18n));
