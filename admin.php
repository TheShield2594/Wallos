<?php
require_once 'includes/header.php';
require_once 'includes/oidc_settings.php';
require_once 'includes/ssrf_helper.php';
require_once 'includes/instance_config.php';
require_once 'includes/categories.php';

if ($isAdmin != 1) {
    header('Location: index.php');
    exit;
}

// get admin settings from admin table, with anything the deployment owns
// applied over them
$adminConfiguration = wallos_get_effective_admin_configuration($db);
$settings = $adminConfiguration['settings'];
$instanceManagedFields = $adminConfiguration['managed_fields'];
$instanceNotes = $adminConfiguration['notes'];

$oidcConfiguration = wallos_get_effective_oidc_configuration($db);
$oidcSettings = $oidcConfiguration['settings'];
$oidcManagedFields = $oidcConfiguration['managed_fields'];
$oidcNotes = $oidcConfiguration['notes'];

$ssrfConfiguration = wallos_get_effective_ssrf_allowlist($db);
$ssrfManagedFields = $ssrfConfiguration['is_managed'] ? ['allowlist' => 'SSRF_ALLOWLIST'] : [];

function oidc_input_attrs($field, $managedFields)
{
    return isset($managedFields[$field]) ? 'disabled data-managed-by="' . htmlspecialchars($managedFields[$field]) . '"' : '';
}

// get user accounts
$stmt = $db->prepare('SELECT id, username, email, is_admin, oidc_sub FROM user ORDER BY id ASC');
$result = $stmt->execute();

$users = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $users[] = $row;
}
$userCount = is_array($users) ? count($users) : 0;

$loginDisabledAllowed = $userCount == 1 && $settings['registrations_open'] == 0;

$adminCount = 0;
$oidcAdminCount = 0;
foreach ($users as $user) {
    if ((int) $user['is_admin'] === 1) {
        $adminCount++;
        if (!empty($user['oidc_sub'])) {
            $oidcAdminCount++;
        }
    }
}
// Password login can be turned off with any number of accounts, once an admin
// can sign in through the identity provider (issue #45).
$passwordLoginDisableAllowed = $oidcAdminCount > 0 || (int) $oidcSettings['password_login_disabled'] === 1;
?>

<?php page_toolbar_start(translate('admin', $i18n)); ?>
<?php page_toolbar_end(); ?>
<section class="contain settings">

    <section class="account-section">
        <header>
            <h2><?= translate('registrations', $i18n) ?></h2>
        </header>
        <div class="admin-form">
            <div class="form-group-inline">
                <input type="checkbox" id="registrations" <?= $settings['registrations_open'] ? 'checked' : '' ?> />
                <label for="registrations"><?= translate('enable_user_registrations', $i18n) ?></label>
            </div>
            <div class="form-group">
                <label for="maxUsers"><?= translate('maximum_number_users', $i18n) ?></label>
                <input type="number" id="maxUsers" autocomplete="off" value="<?= $settings['max_users'] ?>" />
            </div>
            <div class="settings-notes">
                <p>
                    <i class="fa-solid fa-circle-info"></i>
                    <?= translate('max_users_info', $i18n) ?>
                </p>
                <p>
                    <i class="fa-solid fa-circle-info"></i>
                    <?= translate('registrations_disable_login_info', $i18n) ?>
                </p>
            </div>
            <div class="form-group-inline">
                <input type="checkbox" id="requireEmail" <?= $settings['require_email_verification'] ? 'checked' : '' ?>
                    <?= empty($settings['smtp_address']) ? 'disabled' : '' ?> />
                <label for="requireEmail">
                    <?= translate('require_email_verification', $i18n) ?>
                </label>
            </div>
            <?php
            if (empty($settings['smtp_address'])) {
                ?>
                <div class="settings-notes">
                    <p>
                        <i class="fa-solid fa-circle-info"></i>
                        <?= translate('configure_smtp_settings_to_enable', $i18n) ?>
                    </p>
                </div>
                <?php
            }
            ?>
            <div class="form-group">
                <label for="serverUrl"><?= translate('server_url', $i18n) ?></label>
                <input type="text" id="serverUrl" autocomplete="off" value="<?= htmlspecialchars($settings['server_url']) ?>" />
            </div>
            <div class="settings-notes">
                <p>
                    <i class="fa-solid fa-circle-info"></i>
                    <?= translate('server_url_info', $i18n) ?>
                </p>
                <p>
                    <i class="fa-solid fa-circle-info"></i>
                    <?= translate('server_url_password_reset', $i18n) ?>
                </p>
            </div>
            <div class="form-group-inline">
                <input type="checkbox" id="disableLogin" <?= $settings['login_disabled'] ? 'checked' : '' ?>
                    <?= $loginDisabledAllowed ? '' : 'disabled' ?> />
                <label for="disableLogin"><?= translate('disable_login', $i18n) ?></label>
            </div>
            <div class="settings-notes">
                <p>
                    <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                    <?= translate('disable_login_info', $i18n) ?>
                </p>
                <p>
                    <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                    <?= translate('disable_login_info2', $i18n) ?>
                </p>
            </div>
            <div class="buttons">
                <input type="submit" class="thin mobile-grow" value="<?= translate('save', $i18n) ?>"
                    id="saveAccountRegistrations" onClick="saveAccountRegistrationsButton()" />
            </div>
        </div>
    </section>

    <?php
    if ($userCount >= 0) {
        ?>

        <section class="account-section">
            <header>
                <h2><?= translate('user_management', $i18n) ?></h2>
            </header>
            <div class="user-list">
                <?php
                foreach ($users as $user) {
                    $userIsAdmin = (int) $user['is_admin'] === 1;
                    $userIcon = $userIsAdmin ? 'fa-user-shield' : 'fa-user';
                    $isLastAdmin = $userIsAdmin && $adminCount <= 1;
                    $isSelf = (int) $user['id'] === (int) $userId;
                    ?>
                    <div class="form-group-inline" data-userid="<?= $user['id'] ?>">
                        <div class="user-list-row">
                            <div title="<?= translate('username', $i18n) ?>">
                                <div class="user-list-icon">
                                    <i class="fa-solid <?= $userIcon ?>"></i>
                                </div>
                                <?= htmlspecialchars($user['username']) ?>
                                <?php if ($userIsAdmin) { ?>
                                    <span class="user-role-badge"><?= translate('admin', $i18n) ?></span>
                                <?php } ?>
                                <?php if (!empty($user['oidc_sub'])) { ?>
                                    <span class="user-role-badge" title="<?= translate('linked_to_oidc', $i18n) ?>">OIDC</span>
                                <?php } ?>
                            </div>
                            <div title="<?= translate('email', $i18n) ?>">
                                <div class="user-list-icon">
                                    <i class="fa-solid fa-at"></i>
                                </div>
                                <a href="mailto:<?= htmlspecialchars($user['email']) ?>"><?= htmlspecialchars($user['email']) ?></a>
                            </div>
                        </div>
                        <div>
                            <?php if ($userIsAdmin) { ?>
                                <button class="image-button medium<?= $isLastAdmin ? ' disabled' : '' ?>" <?= $isLastAdmin ? 'disabled' : '' ?>
                                    onClick="setUserAdmin(<?= (int) $user['id'] ?>, false)"
                                    title="<?= translate($isLastAdmin ? 'cannot_remove_last_admin' : 'remove_admin', $i18n) ?>">
                                    <i class="fa-solid fa-user-minus"></i>
                                </button>
                            <?php } else { ?>
                                <button class="image-button medium" onClick="setUserAdmin(<?= (int) $user['id'] ?>, true)"
                                    title="<?= translate('make_admin', $i18n) ?>">
                                    <i class="fa-solid fa-user-shield"></i>
                                </button>
                            <?php } ?>
                            <?php
                            if (!$isLastAdmin && !$isSelf) {
                                ?>
                                <button class="image-button medium" onClick="removeUser(<?= $user['id'] ?>)"
                                    title="<?= translate('delete_user', $i18n) ?>">
                                    <i class="fa-solid fa-trash-can"></i>
                                </button>
                                <?php
                            } else {
                                ?>
                                <button class="image-button medium disabled" disabled
                                    title="<?= translate('delete_user', $i18n) ?>">
                                    <i class="fa-solid fa-trash-can"></i>
                                </button>
                                <?php
                            }
                            ?>

                        </div>
                    </div>
                    <?php
                }
                ?>
            </div>
            <div class="settings-notes">
                <p>
                    <i class="fa-solid fa-circle-info"></i>
                    <?= translate('delete_user_info', $i18n) ?>
                </p>
            </div>
            <h2><?= translate('create_user', $i18n) ?></h2>
            <div class="form-group">
                <input type="text" id="newUsername" autocomplete="off"
                    placeholder="<?= translate('username', $i18n) ?>" />
            </div>
            <div class="form-group">
                <input type="email" id="newEmail" autocomplete="off"
                    placeholder="<?= translate('email', $i18n) ?>" />
            </div>
            <div class="form-group-inline">
                <input type="password" id="newPassword" autocomplete="off"
                    placeholder="<?= translate('password', $i18n) ?>" />
                <input type="submit" class="thin" value="<?= translate('add', $i18n) ?>" id="addUserButton"
                    onClick="addUserButton()" />
            </div>
        </section>

        <?php
    }
    ?>

    <?php
    // Every account's API keys (issue #43); admins can revoke any of them.
    require_once 'includes/api_keys.php';
    $allApiKeys = wallos_api_list_keys($db, null);
    ?>
    <section class="account-section">
        <header>
            <h2><?= translate('api_keys', $i18n) ?></h2>
        </header>
        <div class="api-key-list">
            <?php if ($allApiKeys === []) { ?>
                <p class="form-hint"><?= translate('no_api_keys', $i18n) ?></p>
            <?php } ?>
            <?php foreach ($allApiKeys as $apiKey) { ?>
                <div class="api-key-row" data-keyid="<?= (int) $apiKey['id'] ?>">
                    <div class="api-key-details">
                        <span><i class="fa-solid fa-user" aria-hidden="true"></i> <?= htmlspecialchars($apiKey['username'] ?? '?') ?></span>
                        <strong><?= htmlspecialchars($apiKey['name']) ?></strong>
                        <code><?= htmlspecialchars($apiKey['prefix']) ?>&hellip;</code>
                        <span class="api-key-scopes">
                            <?php foreach ($apiKey['scopes'] as $scope) { ?>
                                <span class="user-role-badge<?= $scope === 'admin:read' ? ' is-admin-scope' : '' ?>"><?= htmlspecialchars($scope) ?></span>
                            <?php } ?>
                        </span>
                        <span class="form-hint">
                            <?= translate('last_used', $i18n) ?>
                            <?= $apiKey['last_used_at'] ? htmlspecialchars(substr($apiKey['last_used_at'], 0, 16)) . ' UTC' : translate('never', $i18n) ?>
                        </span>
                    </div>
                    <button class="image-button medium" onClick="adminRevokeApiKey(<?= (int) $apiKey['id'] ?>)"
                        title="<?= translate('revoke', $i18n) ?>" aria-label="<?= translate('revoke', $i18n) ?>"
                        data-confirm="<?= htmlspecialchars(translate('revoke_api_key_confirmation', $i18n)) ?>">
                        <i class="fa-solid fa-ban"></i>
                    </button>
                </div>
            <?php } ?>
        </div>
        <div class="settings-notes">
            <p>
                <i class="fa-solid fa-circle-info"></i>
                <?= translate('admin_api_keys_info', $i18n) ?>
            </p>
        </div>
    </section>

    <?php
    // Default categories for new accounts (issue #31).
    $categoryTemplates = wallos_category_templates($db);
    $templateParents = array_filter($categoryTemplates, function ($template) {
        return (int) ($template['parent_template_id'] ?? 0) === 0;
    });
    $templateHasChildren = [];
    foreach ($categoryTemplates as $template) {
        if ((int) ($template['parent_template_id'] ?? 0) > 0) {
            $templateHasChildren[(int) $template['parent_template_id']] = true;
        }
    }
    $renderTemplateParentSelect = function ($id, $selectedParent, $disabled) use ($templateParents, $i18n) {
        ?>
        <select name="parent" aria-label="<?= translate('parent_category', $i18n) ?>" <?= $disabled ? 'disabled' : '' ?>>
            <option value=""><?= translate('top_level_category', $i18n) ?></option>
            <?php foreach ($templateParents as $parentId => $parent) {
                if ($id !== null && (int) $parentId === (int) $id) {
                    continue;
                } ?>
                <option value="<?= (int) $parentId ?>" <?= (int) $selectedParent === (int) $parentId ? 'selected' : '' ?>><?= htmlspecialchars(htmlspecialchars_decode($parent['name'])) ?></option>
            <?php } ?>
        </select>
        <?php
    };
    ?>
    <section class="account-section">
        <header>
            <h2><?= translate('default_categories', $i18n) ?></h2>
        </header>
        <div id="categoryTemplates">
            <?php foreach ($categoryTemplates as $templateId => $template) { ?>
                <div class="form-group-inline<?= (int) ($template['parent_template_id'] ?? 0) > 0 ? ' is-subcategory' : '' ?>" data-templateid="<?= (int) $templateId ?>">
                    <input type="text" name="name" autocomplete="off" maxlength="100" value="<?= htmlspecialchars(htmlspecialchars_decode($template['name'])) ?>"
                        aria-label="<?= translate('category', $i18n) ?>">
                    <?php $renderTemplateParentSelect($templateId, $template['parent_template_id'] ?? 0, isset($templateHasChildren[(int) $templateId])); ?>
                    <button class="image-button medium" name="save" onClick="saveCategoryTemplate(<?= (int) $templateId ?>)"
                        title="<?= translate('save_category', $i18n) ?>">
                        <i class="fa-solid fa-check"></i>
                    </button>
                    <button class="image-button medium" onClick="deleteCategoryTemplate(<?= (int) $templateId ?>)"
                        title="<?= translate('delete_category', $i18n) ?>">
                        <i class="fa-solid fa-trash-can"></i>
                    </button>
                </div>
            <?php } ?>
            <div class="form-group-inline" id="newCategoryTemplate">
                <input type="text" name="name" autocomplete="off" maxlength="100" placeholder="<?= translate('category', $i18n) ?>"
                    aria-label="<?= translate('category', $i18n) ?>">
                <?php $renderTemplateParentSelect(null, 0, false); ?>
                <input type="submit" class="thin" value="<?= translate('add', $i18n) ?>" onClick="addCategoryTemplate(this)" />
            </div>
        </div>
        <div class="buttons">
            <input type="submit" class="thin mobile-grow" id="applyCategoryTemplates" value="<?= translate('apply_to_all_users', $i18n) ?>"
                onClick="applyCategoryTemplates(this)" <?= $categoryTemplates === [] ? 'disabled' : '' ?> />
        </div>
        <div class="settings-notes">
            <p>
                <i class="fa-solid fa-circle-info"></i>
                <?= translate('default_categories_info', $i18n) ?>
            </p>
        </div>
    </section>

    <section class="account-section">
        <header>
            <h2><?= translate('oidc_settings', $i18n) ?></h2>
        </header>
        <div class="admin-form">
            <div class="form-group-inline">
                <input type="checkbox" id="oidcEnabled" <?= $oidcConfiguration['enabled'] ? 'checked' : '' ?>
                    <?= oidc_input_attrs('enabled', $oidcManagedFields) ?>
                    onchange="toggleOidcEnabled()" />
                <label for="oidcEnabled"><?= translate('oidc_oauth_enabled', $i18n) ?></label>
            </div>
            <div class="form-group">
                <input type="text" id="oidcName" placeholder="Provider Name" autocomplete="off"
                    value="<?= htmlspecialchars($oidcSettings['name']) ?>" <?= oidc_input_attrs('name', $oidcManagedFields) ?> />
            </div>
            <div class="form-group">
                <input type="text" id="oidcClientId" placeholder="Client ID" autocomplete="off"
                    value="<?= htmlspecialchars($oidcSettings['client_id']) ?>" <?= oidc_input_attrs('client_id', $oidcManagedFields) ?> />
            </div>
            <div class="form-group">
                <input type="text" id="oidcClientSecret" placeholder="Client Secret" autocomplete="off"
                    value="<?= htmlspecialchars($oidcSettings['client_secret']) ?>" <?= oidc_input_attrs('client_secret', $oidcManagedFields) ?> />
            </div>
            <div class="form-group">
                <input type="text" id="oidcAuthUrl" placeholder="Auth URL" autocomplete="off"
                    value="<?= htmlspecialchars($oidcSettings['authorization_url']) ?>" <?= oidc_input_attrs('authorization_url', $oidcManagedFields) ?> />
            </div>
            <div class="form-group">
                <input type="text" id="oidcTokenUrl" placeholder="Token URL" autocomplete="off"
                    value="<?= htmlspecialchars($oidcSettings['token_url']) ?>" <?= oidc_input_attrs('token_url', $oidcManagedFields) ?> />
            </div>
            <div class="form-group">
                <input type="text" id="oidcUserInfoUrl" placeholder="User Info URL" autocomplete="off"
                    value="<?= htmlspecialchars($oidcSettings['user_info_url']) ?>" <?= oidc_input_attrs('user_info_url', $oidcManagedFields) ?> />
            </div>
            <div class="form-group">
                <input type="text" id="oidcRedirectUrl" placeholder="Redirect URL" autocomplete="off"
                    value="<?= htmlspecialchars($oidcSettings['redirect_url']) ?>" <?= oidc_input_attrs('redirect_url', $oidcManagedFields) ?> />
            </div>
            <div class="form-group">
                <input type="text" id="oidcLogoutUrl" placeholder="Logout URL" autocomplete="off"
                    value="<?= htmlspecialchars($oidcSettings['logout_url']) ?>" <?= oidc_input_attrs('logout_url', $oidcManagedFields) ?> />
            </div>
            <div class="form-group">
                <input type="text" id="oidcUserIdentifierField" placeholder="User Identifier Field" autocomplete="off"
                    value="<?= htmlspecialchars($oidcSettings['user_identifier_field']) ?>" <?= oidc_input_attrs('user_identifier_field', $oidcManagedFields) ?> />
            </div>
            <div class="form-group">
                <input type="text" id="oidcScopes" placeholder="Scopes" autocomplete="off"
                    value="<?= htmlspecialchars($oidcSettings['scopes']) ?>" <?= oidc_input_attrs('scopes', $oidcManagedFields) ?> />
            </div>
            <div class="form-group">
                <input type="hidden" id="oidcAuthStyle" placeholder="Auth Style" autocomplete="off"
                    value="<?= htmlspecialchars($oidcSettings['auth_style']) ?>" />
            </div>
            <div class="form-group-inline">
                <input type="checkbox" id="oidcAutoCreateUser" <?= $oidcSettings['auto_create_user'] ? 'checked' : '' ?>
                    <?= oidc_input_attrs('auto_create_user', $oidcManagedFields) ?> />
                <label for="oidcAutoCreateUser"><?= translate('create_user_automatically', $i18n) ?></label>
            </div>
            <div class="form-group-inline">
                <input type="checkbox" id="oidcPasswordLoginDisabled"
                    <?= $oidcSettings['password_login_disabled'] ? 'checked' : '' ?>
                    data-was-checked="<?= $oidcSettings['password_login_disabled'] ? '1' : '0' ?>"
                    data-confirm="<?= htmlspecialchars(translate('disable_password_login_confirmation', $i18n)) ?>"
                    <?= isset($oidcManagedFields['password_login_disabled']) ? oidc_input_attrs('password_login_disabled', $oidcManagedFields) : ($passwordLoginDisableAllowed ? '' : 'disabled') ?> />
                <label for="oidcPasswordLoginDisabled"><?= translate('disable_password_login', $i18n) ?></label>
            </div>
            <div class="settings-notes">
                <p>
                    <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                    <?= translate($passwordLoginDisableAllowed ? 'disable_password_login_info' : 'password_login_needs_oidc_admin', $i18n) ?>
                </p>
            </div>
            <div class="form-group">
                <label for="oidcRolesClaim"><?= translate('oidc_roles_claim', $i18n) ?></label>
                <input type="text" id="oidcRolesClaim" placeholder="groups" autocomplete="off"
                    value="<?= htmlspecialchars($oidcSettings['roles_claim']) ?>" <?= oidc_input_attrs('roles_claim', $oidcManagedFields) ?> />
            </div>
            <div class="form-group">
                <label for="oidcAdminValues"><?= translate('oidc_admin_values', $i18n) ?></label>
                <input type="text" id="oidcAdminValues" placeholder="tally-admins" autocomplete="off"
                    value="<?= htmlspecialchars($oidcSettings['admin_values']) ?>" <?= oidc_input_attrs('admin_values', $oidcManagedFields) ?> />
            </div>
            <div class="settings-notes">
                <p>
                    <i class="fa-solid fa-circle-info"></i>
                    <?= translate('oidc_role_mapping_info', $i18n) ?>
                </p>
            </div>
            <div class="form-group-inline">
                <input type="checkbox" id="oidcRequireEmailVerified"
                    <?= $oidcSettings['require_email_verified'] ? 'checked' : '' ?>
                    <?= oidc_input_attrs('require_email_verified', $oidcManagedFields) ?> />
                <label for="oidcRequireEmailVerified"><?= translate('require_email_verified_linking', $i18n) ?></label>
            </div>
            <?php if (!empty($oidcManagedFields) || !empty($oidcNotes)): ?>
                <div class="settings-notes">
                    <?php if (!empty($oidcManagedFields)): ?>
                        <p>
                            <i class="fa-solid fa-circle-info"></i>
                            OIDC fields managed by environment variables are shown here but cannot be edited in the UI.
                        </p>
                    <?php endif; ?>
                    <?php foreach ($oidcNotes as $oidcNote): ?>
                        <p>
                            <i class="fa-solid fa-circle-info"></i>
                            <?= htmlspecialchars($oidcNote) ?>
                        </p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="buttons">
                <input type="submit" class="thin mobile-grow" value="<?= translate('save', $i18n) ?>"
                    id="saveOidcSettingsButton" onClick="saveOidcSettingsButton()" />
            </div>
        </div>

    </section>

    <section class="account-section">
        <header>
            <h2><?= translate('smtp_settings', $i18n) ?></h2>
        </header>
        <div class="admin-form">
            <div class="form-group-inline">
                <input type="text" name="smtpaddress" id="smtpaddress" autocomplete="off"
                    placeholder="<?= translate('smtp_address', $i18n) ?>" value="<?= htmlspecialchars($settings['smtp_address']) ?>"
                    <?= oidc_input_attrs('smtp_address', $instanceManagedFields) ?> />
                <input type="text" name="smtpport" id="smtpport" autocomplete="off"
                    placeholder="<?= translate('port', $i18n) ?>" class="one-third" value="<?= htmlspecialchars($settings['smtp_port']) ?>"
                    <?= oidc_input_attrs('smtp_port', $instanceManagedFields) ?> />
            </div>
            <div class="form-group-inline">
                <div>
                    <input type="radio" name="encryption" id="encryptionnone" value="none"
                        <?= empty($settings['encryption']) || $settings['encryption'] == "none" ? "checked" : "" ?>
                        <?= oidc_input_attrs('encryption', $instanceManagedFields) ?> />
                    <label for="encryptionnone"><?= translate('none', $i18n) ?></label>
                </div>
                <div>
                    <input type="radio" name="encryption" id="encryptiontls" value="tls"
                        <?= $settings['encryption'] == "tls" ? "checked" : "" ?>
                        <?= oidc_input_attrs('encryption', $instanceManagedFields) ?> />
                    <label for="encryptiontls"><?= translate('tls', $i18n) ?></label>
                </div>
                <div>
                    <input type="radio" name="encryption" id="encryptionssl" value="ssl"
                        <?= $settings['encryption'] == "ssl" ? "checked" : "" ?>
                        <?= oidc_input_attrs('encryption', $instanceManagedFields) ?> />
                    <label for="encryptionssl"><?= translate('ssl', $i18n) ?></label>
                </div>
            </div>
            <div class="form-group-inline">
                <input type="text" name="smtpusername" id="smtpusername" autocomplete="off"
                    placeholder="<?= translate('smtp_username', $i18n) ?>" value="<?= htmlspecialchars($settings['smtp_username']) ?>"
                    <?= oidc_input_attrs('smtp_username', $instanceManagedFields) ?> />
            </div>
            <div class="form-group-inline">
                <?php if (isset($instanceManagedFields['smtp_password'])): ?>
                    <?php /* The value is never rendered when the deployment owns it: a mounted
                             secret that reaches the page source has left the place it was
                             mounted into. The field says it is set, and nothing more. */ ?>
                    <input type="password" name="smtppassword" id="smtppassword" autocomplete="off"
                        placeholder="<?= translate('smtp_password', $i18n) ?>" value=""
                        <?= oidc_input_attrs('smtp_password', $instanceManagedFields) ?> />
                <?php else: ?>
                    <input type="password" name="smtppassword" id="smtppassword" autocomplete="off"
                        placeholder="<?= translate('smtp_password', $i18n) ?>" value="<?= htmlspecialchars($settings['smtp_password']) ?>" />
                <?php endif; ?>
            </div>
            <div class="form-group-inline">
                <input type="text" name="fromemail" id="fromemail" autocomplete="off"
                    placeholder="<?= translate('from_email', $i18n) ?>" value="<?= htmlspecialchars($settings['from_email']) ?>"
                    <?= oidc_input_attrs('from_email', $instanceManagedFields) ?> />
            </div>
            <div class="buttons">
                <input type="button" class="secondary-button thin mobile-grow" value="<?= translate('test', $i18n) ?>"
                    id="testSmtpSettingsButton" onClick="testSmtpSettingsButton()" />
                <input type="submit" class="thin mobile-grow" value="<?= translate('save', $i18n) ?>"
                    id="saveSmtpSettingsButton" onClick="saveSmtpSettingsButton()" />
            </div>
            <div class="settings-notes">
                <p>
                    <i class="fa-solid fa-circle-info"></i> <?= translate('smtp_info', $i18n) ?>
                </p>
                <p>
                    <i class="fa-solid fa-circle-info"></i>
                    <?= translate('smtp_usage_info', $i18n) ?>
                </p>
                <?php if (!empty($instanceManagedFields)): ?>
                    <p>
                        <i class="fa-solid fa-circle-info"></i>
                        Fields managed by environment variables are shown here but cannot be
                        edited in the UI: <?= htmlspecialchars(implode(', ', $instanceManagedFields)) ?>.
                    </p>
                <?php endif; ?>
                <?php foreach ($instanceNotes as $instanceNote): ?>
                    <p>
                        <i class="fa-solid fa-circle-info"></i>
                        <?= htmlspecialchars($instanceNote) ?>
                    </p>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="account-section">
    <header>
        <h2><?= translate('security_settings', $i18n) ?></h2> </header>
    <div class="admin-form">
        <div class="form-group-inline">
            <input type="text" name="local_webhook_notifications_allowlist" id="local_webhook_notifications_allowlist" autocomplete="off"
                placeholder="e.g., 192.168.1.5:8123, homeassistant.local" value="<?= htmlspecialchars($ssrfConfiguration['raw'], ENT_QUOTES, 'UTF-8') ?>" <?= oidc_input_attrs('allowlist', $ssrfManagedFields) ?> />
        </div>

        <div class="settings-notes">
            <p>
                <i class="fa-solid fa-circle-info"></i>
                <?= translate('ssrf_protection_info', $i18n) ?>
            </p>
            <p>
                <i class="fa-solid fa-circle-info"></i>
                <?= translate('local_webhook_info', $i18n) ?>
            </p>
            <?php if ($ssrfConfiguration['is_managed']): ?>
                <p>
                    <i class="fa-solid fa-circle-info"></i>
                    <?= translate('ssrf_allowlist_env_managed', $i18n) ?>
                </p>
            <?php endif; ?>
        </div>

        <div class="form-group-inline">
            <input type="checkbox" id="allow_standard_users_local_webhooks" name="allow_standard_users_local_webhooks"
                <?= $ssrfConfiguration['allow_standard_users'] ? 'checked' : '' ?> />
            <label for="allow_standard_users_local_webhooks"><?= translate('allow_standard_users_local_webhooks', $i18n) ?></label>
        </div>

        <div class="settings-notes">
            <p>
                <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                <?= translate('allow_standard_users_local_webhooks_info', $i18n) ?>
            </p>
        </div>

        <div class="buttons">
            <input type="submit" class="thin mobile-grow" value="<?= translate('save', $i18n) ?>"
                id="saveSecuritySettingsButton" onClick="saveSecuritySettingsButton()" />
        </div>
    </div>
</section>

    <?php
    // Get latest version from admin table
    if (!is_null($settings['latest_version'])) {
        $latestVersion = $settings['latest_version'];
        $hasUpdate = version_compare($version, $latestVersion) == -1;
    } else {
        $hasUpdate = false;
    }

    // find unused upload logos

    // Get all logos in the subscriptions table
    $query = 'SELECT logo, logo_variant FROM subscriptions';
    $stmt = $db->prepare($query);
    $result = $stmt->execute();

    $logosOnDisk = [];
    $logosOnDB = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $logosOnDB[] = $row['logo'];
        $logosOnDB[] = $row['logo_variant'];
    }

    // Get all logos in the payment_methods table
    $query = 'SELECT icon FROM payment_methods';
    $stmt = $db->prepare($query);
    $result = $stmt->execute();

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        if (!strstr($row['icon'], "images/uploads/icons/")) {
            $logosOnDB[] = $row['icon'];
        }
    }

    $logosOnDB = array_unique($logosOnDB);

    // Get all logos in the uploads folder
    $uploadDir = 'images/uploads/logos/';
    $uploadFiles = scandir($uploadDir);

    foreach ($uploadFiles as $file) {
        if ($file != '.' && $file != '..' && $file != 'avatars') {
            $logosOnDisk[] = ['logo' => $file];
        }
    }

    // Find unused logos
    $unusedLogos = [];
    foreach ($logosOnDisk as $disk) {
        $found = false;
        foreach ($logosOnDB as $dbLogo) {
            if ($disk['logo'] == $dbLogo) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            $unusedLogos[] = $disk;
        }
    }

    $logosToDelete = count($unusedLogos);

    ?>

    <section class="account-section">
        <header>
            <h2>
                <?= translate('maintenance_tasks', $i18n) ?>
            </h2>
        </header>
        <div class="maintenance-tasks">
            <h3><?= translate('update', $i18n) ?></h3>
            <div class="form-group">
                <?php
                if ($hasUpdate) {
                    ?>
                    <div class="updates-list">
                        <p class="updates-list-title">
                            <i class="fa-solid fa-arrow-up"></i>
                            <?= translate('new_version_available', $i18n) ?>.
                        </p>
                        <p>
                            <?= translate('current_version', $i18n) ?>:
                            <span>
                                <?= htmlspecialchars($version) ?>
                                <a href="https://github.com/<?= htmlspecialchars($githubRepository) ?>/releases/tag/<?= htmlspecialchars($releaseTagPrefix) ?><?= htmlspecialchars($version) ?>"
                                    target="_blank" title="<?= translate('external_url', $i18n) ?>" rel="noreferrer">
                                    <i class="fa-solid fa-arrow-up-right-from-square"></i>
                                </a>
                            </span>
                        </p>
                        <p>
                            <?= translate('latest_version', $i18n) ?>:
                            <span class="updates-list-latest">
                                <?= htmlspecialchars($latestVersion) ?>
                                <a href="https://github.com/<?= htmlspecialchars($githubRepository) ?>/releases/tag/<?= htmlspecialchars($releaseTagPrefix) ?><?= htmlspecialchars($latestVersion) ?>"
                                    target="_blank" title="<?= translate('external_url', $i18n) ?>" rel="noreferrer">
                                    <i class="fa-solid fa-arrow-up-right-from-square"></i>
                                </a>
                            </span>
                        </p>
                    </div>
                    <?php
                } else {
                    ?>
                    <?= translate('on_current_version', $i18n) ?>
                    <?php
                }
                ?>
            </div>
            <div class="form-group-inline">
                <input type="checkbox" id="updateNotification" <?= $settings['update_notification'] ? 'checked' : '' ?>
                    onchange="toggleUpdateNotification()" />
                <label for="updateNotification"><?= translate('show_update_notification', $i18n) ?></label>
            </div>
            <h3><?= translate('orphaned_logos', $i18n) ?></h3>
            <div class="form-group-inline">
                <input type="button" class="button thin mobile-grow" value="<?= translate('delete', $i18n) ?>"
                    id="deleteUnusedLogos" onClick="deleteUnusedLogos()" <?= $logosToDelete == 0 ? 'disabled' : '' ?> />
                <span class="number-of-logos bold"><?= $logosToDelete ?></span>
                <?= translate('orphaned_logos', $i18n) ?>
            </div>
            <h3><?= translate('cronjobs', $i18n) ?></h3>
            <div>
                <div class="inline-row">
                    <input type="button" value="Check for Updates" class="button tiny mobile-grow"
                        onclick="executeCronJob('checkforupdates')">
                    <input type="button" value="Send Notifications" class="button tiny mobile-grow"
                        onclick="executeCronJob('sendnotifications')">
                    <input type="button" value="Send Cancellation Notifications" class="button tiny mobile-grow"
                        onclick="executeCronJob('sendcancellationnotifications')">
                    <input type="button" value="Send Password Reset Emails" class="button tiny mobile-grow"
                        onclick="executeCronJob('sendresetpasswordemails')">
                    <input type="button" value="Send Verification Emails" class="button tiny mobile-grow"
                        onclick="executeCronJob('sendverificationemails')">
                    <input type="button" value="Update Exchange Rates" class="button tiny mobile-grow"
                        onclick="executeCronJob('updateexchange')">
                    <input type="button" value="Update Next Payments" class="button tiny mobile-grow"
                        onclick="executeCronJob('updatenextpayment')">
                    <input type="button" value="Store Total Yearly Cost" class="button tiny mobile-grow"
                        onclick="executeCronJob('storetotalyearlycost')">
                    <input type="button" value="Generate AI Recommendations" class="button tiny mobile-grow"
                        onclick="executeCronJob('generaterecommendations')">    
                </div>
                <div class="inline-row">
                    <textarea id="cronjobResult" class="thin" readonly></textarea>
                </div>
            </div>
        </div>
    </section>

    <section class="account-section">
        <header>
            <h2><?= translate('backup_and_restore', $i18n) ?></h2>
        </header>
        <div class="form-group-inline">
            <input type="button" class="button thin mobile-grow" value="<?= translate('backup', $i18n) ?>" id="backupDB"
                onClick="backupDB()" />
            <input type="button" class="secondary-button thin mobile-grow" value="<?= translate('restore', $i18n) ?>"
                id="restoreDB" onClick="openRestoreDBFileSelect()" />
            <input type="file" name="restoreDBFile" id="restoreDBFile" style="display: none;" onChange="restoreDB()"
                accept=".zip">
        </div>
        <div class="settings-notes">
            <p>
                <i class="fa-solid fa-circle-info"></i>
                <?= translate('restore_info', $i18n) ?>
            </p>
        </div>
    </section>

</section>
<script src="scripts/admin.js?<?= $version ?>"></script>

<?php
require_once 'includes/footer.php';
?>
