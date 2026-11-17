<?php
/*
This API Endpoint accepts POST requests only.
It receives the following parameters:
- api_key: the API key of the user.
- action: the action to perform ('add', 'edit', 'delete').
- name: (required for 'add' and 'edit') the name of the category.
- id / categoryId: (required for 'edit' and 'delete') the ID of the category.
- parent_id: (optional for 'edit') a top-level category to nest it under, or empty for top level. Sub-categories go one level deep; deleting a parent makes its children top-level.

It returns a JSON object with the following properties:
- success: whether the request was successful (boolean).
- title: the title of the response (string).
- message: detailed information or error message (string).
- categoryId: (only for successful 'add' action) the ID of the newly created category (integer).

Example response:
{
  "success": true,
  "title": "Category added",
  "categoryId": 4,
  "message": "Category added successfully."
}
*/

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/categories.php';
require_once '../../includes/inputvalidation.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'title' => 'Invalid request method',
        'message' => 'Only POST requests are allowed.'
    ]);
    exit;
}

$apiKey = $_POST['api_key'] ?? $_POST['apiKey'] ?? null;

// Authenticate user first
if (!$apiKey) {
    echo json_encode([
        'success' => false,
        'title' => 'Missing API key',
        'message' => 'API key is required.'
    ]);
    exit;
}

$sql = "SELECT * FROM user WHERE api_key = :apiKey";
$stmt = $db->prepare($sql);
$stmt->bindValue(':apiKey', $apiKey, SQLITE3_TEXT);
$result = $stmt->execute();
$user = $result->fetchArray(SQLITE3_ASSOC);

if (!$user) {
    echo json_encode([
        'success' => false,
        'title' => 'Unauthorized',
        'message' => 'Invalid API key.'
    ]);
    exit;
}

$userId = $user['id'];
$action = $_POST['action'] ?? null;

if (!$action || !in_array($action, ['add', 'edit', 'delete'], true)) {
    echo json_encode([
        'success' => false,
        'title' => 'Invalid action',
        'message' => 'Action must be "add", "edit", or "delete".'
    ]);
    exit;
}

switch ($action) {
    case 'add':
        $name = $_POST['name'] ?? null;
        if (!$name || trim($name) === '') {
            echo json_encode([
                'success' => false,
                'title' => 'Missing parameter',
                'message' => 'Parameter "name" is required and cannot be empty.'
            ]);
            exit;
        }
        $name = validate($name);

        // Get next order sequence
        $stmtOrder = $db->prepare('SELECT MAX("order") as maxOrder FROM categories WHERE user_id = :userId');
        $stmtOrder->bindParam(':userId', $userId, SQLITE3_INTEGER);
        $resultOrder = $stmtOrder->execute();
        $rowOrder = $resultOrder->fetchArray(SQLITE3_ASSOC);
        $maxOrder = $rowOrder['maxOrder'] ?? 0;
        $order = $maxOrder + 1;

        // Insert
        $sqlInsert = 'INSERT INTO categories ("name", "order", "user_id") VALUES (:name, :order, :userId)';
        $stmtInsert = $db->prepare($sqlInsert);
        $stmtInsert->bindParam(':name', $name, SQLITE3_TEXT);
        $stmtInsert->bindParam(':order', $order, SQLITE3_INTEGER);
        $stmtInsert->bindParam(':userId', $userId, SQLITE3_INTEGER);
        $resultInsert = $stmtInsert->execute();

        if ($resultInsert) {
            echo json_encode([
                'success' => true,
                'title' => 'Category added',
                'categoryId' => $db->lastInsertRowID(),
                'message' => 'Category added successfully.'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'title' => 'Database error',
                'message' => 'Failed to add category.'
            ]);
        }
        break;

    case 'edit':
        $categoryId = $_POST['categoryId'] ?? $_POST['id'] ?? null;
        $name = $_POST['name'] ?? null;

        if (!$categoryId || !$name || trim($name) === '') {
            echo json_encode([
                'success' => false,
                'title' => 'Missing parameters',
                'message' => 'Parameters "id" (or "categoryId") and "name" are required.'
            ]);
            exit;
        }
        $name = validate($name);
        $categoryId = intval($categoryId);

        // Check ownership
        $checkSql = "SELECT * FROM categories WHERE id = :categoryId AND user_id = :userId";
        $checkStmt = $db->prepare($checkSql);
        $checkStmt->bindValue(':categoryId', $categoryId, SQLITE3_INTEGER);
        $checkStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $checkResult = $checkStmt->execute();
        $category = $checkResult->fetchArray(SQLITE3_ASSOC);

        if (!$category) {
            echo json_encode([
                'success' => false,
                'title' => 'Unauthorized or Not Found',
                'message' => 'Category not found or does not belong to you.'
            ]);
            exit;
        }

        // Optional parent (see includes/categories.php)
        $parentGiven = array_key_exists('parent_id', $_POST);
        $parentId = $parentGiven && ctype_digit((string) $_POST['parent_id']) && (int) $_POST['parent_id'] > 0 ? (int) $_POST['parent_id'] : null;
        // The parent check and the update share one write lock.
        $update = wallos_update_category($db, $userId, $categoryId, $name, $parentGiven, $parentId);
        if ($update['error'] !== null) {
            echo json_encode([
                'success' => false,
                'title' => 'Invalid parent',
                'message' => $update['error']
            ]);
            exit;
        }
        $resultEdit = $update['success'];

        if ($resultEdit) {
            echo json_encode([
                'success' => true,
                'title' => 'Category updated',
                'message' => 'Category updated successfully.'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'title' => 'Database error',
                'message' => 'Failed to update category.'
            ]);
        }
        break;

    case 'delete':
        $categoryId = $_POST['categoryId'] ?? $_POST['id'] ?? null;

        if (!$categoryId) {
            echo json_encode([
                'success' => false,
                'title' => 'Missing parameter',
                'message' => 'Parameter "id" (or "categoryId") is required.'
            ]);
            exit;
        }
        $categoryId = intval($categoryId);

        // Cannot delete category 1 (default fallback)
        if ($categoryId === 1) {
            echo json_encode([
                'success' => false,
                'title' => 'Cannot delete category',
                'message' => 'The default category cannot be deleted.'
            ]);
            exit;
        }

        // Check ownership
        $checkSql = "SELECT * FROM categories WHERE id = :categoryId AND user_id = :userId";
        $checkStmt = $db->prepare($checkSql);
        $checkStmt->bindValue(':categoryId', $categoryId, SQLITE3_INTEGER);
        $checkStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $checkResult = $checkStmt->execute();
        $category = $checkResult->fetchArray(SQLITE3_ASSOC);

        if (!$category) {
            echo json_encode([
                'success' => false,
                'title' => 'Unauthorized or Not Found',
                'message' => 'Category not found or does not belong to you.'
            ]);
            exit;
        }

        // Check if category is in use
        $checkUseSql = "SELECT COUNT(*) FROM subscriptions WHERE category_id = :categoryId AND user_id = :userId";
        $checkUseStmt = $db->prepare($checkUseSql);
        $checkUseStmt->bindParam(':categoryId', $categoryId, SQLITE3_INTEGER);
        $checkUseStmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
        $checkUseResult = $checkUseStmt->execute();
        $row = $checkUseResult->fetchArray();
        $count = $row[0] ?? 0;

        if ($count > 0) {
            echo json_encode([
                'success' => false,
                'title' => 'Category in use',
                'message' => 'This category cannot be deleted because it is in use by one or more subscriptions.'
            ]);
            exit;
        }

        // Delete
        // Its sub-categories become top-level categories, in the same transaction.
        $resultDelete = wallos_delete_category($db, $userId, $categoryId);

        if ($resultDelete) {
            $stmtOrphans = $db->prepare("UPDATE categories SET parent_id = NULL WHERE parent_id = :categoryId AND user_id = :userId");
            $stmtOrphans->bindParam(':categoryId', $categoryId, SQLITE3_INTEGER);
            $stmtOrphans->bindParam(':userId', $userId, SQLITE3_INTEGER);
            $resultDelete = $stmtOrphans->execute();
        }

        if ($resultDelete) {
            echo json_encode([
                'success' => true,
                'title' => 'Category deleted',
                'message' => 'Category deleted successfully.'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'title' => 'Database error',
                'message' => 'Failed to delete category.'
            ]);
        }
        break;
}

$db->close();
?>
