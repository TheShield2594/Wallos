<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/cost_sharing.php';
require_once '../../includes/inputvalidation.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/households.php';

$action = $_POST['action'] ?? '';

switch ($action) {
    case 'add':
        handleAddMember($db, $userId, $i18n);
        break;
    case 'edit':
        handleEditMember($db, $userId, $i18n);
        break;
    case 'delete':
        handleDeleteMember($db, $userId, $i18n);
        break;
    default:
        echo translate('error', $i18n);
        break;
}

function handleAddMember($db, $userId, $i18n)
{
    $householdName = "Member";
    $sqlInsert = "INSERT INTO household (name, user_id) VALUES (:name, :userId)";
    $stmtInsert = $db->prepare($sqlInsert);
    $stmtInsert->bindParam(':name', $householdName, SQLITE3_TEXT);
    $stmtInsert->bindParam(':userId', $userId, SQLITE3_INTEGER);
    $resultInsert = $stmtInsert->execute();

    if ($resultInsert) {
        $householdId = $db->lastInsertRowID();
        $response = [
            "success" => true,
            "householdId" => $householdId,
        ];
        echo json_encode($response);
    } else {
        $response = [
            "success" => false,
            "message" => translate('failed_add_household', $i18n)
        ];
        echo json_encode($response);
    }
}

function handleEditMember($db, $userId, $i18n)
{
    if (isset($_POST['memberId']) && $_POST['memberId'] != "" && isset($_POST['name']) && $_POST['name'] != "") {
        $memberId = $_POST['memberId'];
        $name = validate($_POST['name']);
        $email = $_POST['email'] ? $_POST['email'] : "";
        $email = validate($email);
        // The label and its optional link to an account of the shared
        // household (issue #59; left out of the request = unchanged) are saved
        // together, under the write lock that leaving or removal takes, so a
        // link is never checked against a membership that changes before it
        // is written, and a refused link keeps the old name and email.
        $linked = array_key_exists('linked_user_id', $_POST)
            ? ($_POST['linked_user_id'] === '' ? null : (int) $_POST['linked_user_id'])
            : false;
        $result = wallos_household_transaction($db, function () use ($db, $name, $email, $memberId, $userId, $linked) {
            $sql = "UPDATE household SET name = :name, email = :email WHERE id = :memberId AND user_id = :userId";
            $stmt = $db->prepare($sql);
            $stmt->bindValue(':name', $name, SQLITE3_TEXT);
            $stmt->bindValue(':email', $email, SQLITE3_TEXT);
            $stmt->bindValue(':memberId', (int) $memberId, SQLITE3_INTEGER);
            $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
            if ($stmt->execute() === false) {
                return false;
            }
            if ($linked === false) {
                return true;
            }

            return wallos_household_set_payer_link($db, $userId, (int) $memberId, $linked)['success'];
        });

        if ($result) {
            $response = [
                "success" => true,
                "message" => translate('member_saved', $i18n)
            ];
            echo json_encode($response);
        } else {
            $response = [
                "success" => false,
                "message" => translate('failed_edit_household', $i18n)
            ];
            echo json_encode($response);
        }
    } else {
        $response = [
            "success" => false,
            "message" => translate('fill_all_fields', $i18n)
        ];
        echo json_encode($response);
    }
}

function handleDeleteMember($db, $userId, $i18n)
{
    if (isset($_POST['memberId']) && $_POST['memberId'] != "" && $_POST['memberId'] != 1) {
        // The account holder, a payer or a member in a split can't be deleted;
        // the checks and the delete share one write lock.
        $outcome = wallos_delete_household_member($db, $userId, (int) $_POST['memberId']);
        $messages = ['deleted' => 'member_removed', 'in_use' => 'household_in_use', 'self' => 'cant_delete_member'];
        echo json_encode([
            "success" => $outcome === 'deleted',
            "message" => translate($messages[$outcome] ?? 'failed_remove_household', $i18n)
        ]);
    } else {
        $response = [
            "success" => false,
            "message" => translate('failed_remove_household', $i18n)
        ];
        echo json_encode($response);
    }
}

?>