<?php
/*
  Shared household management (see includes/households.php).

  POST JSON {"action": ..., ...}
    create   {"name"}                       start a household (you own it)
    invite   {"identifier", "role"}         owner: invite an account by username or email
    respond  {"household_id", "accept"}     accept or decline an invite
    role     {"user_id", "role"}            owner: make a member a viewer or editor
    remove   {"user_id"}                    owner: remove a member or withdraw an invite
    leave    {}                             member: leave the household
    delete   {}                             owner: delete the household
*/
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/households.php';

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
$action = is_array($data) ? ($data['action'] ?? '') : '';

switch ($action) {
    case 'create':
        $outcome = wallos_household_create($db, $userId, $data['name'] ?? '');
        break;
    case 'invite':
        $outcome = wallos_household_invite($db, $userId, $data['identifier'] ?? '', $data['role'] ?? 'viewer');
        break;
    case 'respond':
        $outcome = wallos_household_respond($db, $userId, (int) ($data['household_id'] ?? 0), !empty($data['accept']));
        break;
    case 'role':
        $outcome = wallos_household_set_role($db, $userId, (int) ($data['user_id'] ?? 0), $data['role'] ?? '');
        break;
    case 'remove':
        $outcome = wallos_household_remove_member($db, $userId, (int) ($data['user_id'] ?? 0));
        break;
    case 'leave':
        $outcome = wallos_household_leave($db, $userId);
        break;
    case 'delete':
        $outcome = wallos_household_delete($db, $userId);
        break;
    default:
        $outcome = ['success' => false, 'error' => 'invalid_action'];
}

$messages = [
    'invalid_name' => 'household_error_name',
    'already_in_household' => 'household_error_already_member',
    'user_not_found' => 'household_error_user_not_found',
    'cannot_invite_self' => 'household_error_self',
    'owner_cannot_leave' => 'household_error_owner_leave',
];

echo json_encode([
    'success' => $outcome['success'],
    'message' => $outcome['success']
        ? translate('success', $i18n)
        : translate($messages[$outcome['error'] ?? ''] ?? 'error', $i18n),
]);
