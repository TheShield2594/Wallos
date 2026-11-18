<?php
require_once 'includes/header.php';

// Fetch the avatars belonging to the logged-in user
$uploadedAvatars = [];

$stmt = $db->prepare("SELECT path FROM uploaded_avatars WHERE user_id = :user_id");
$stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();

while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $uploadedAvatars[] = $row['path'];
}
?>

<script src="scripts/libs/sortable.min.js"></script>
<script src="scripts/libs/qrcode.min.js"></script>
<style>
    .logo-preview:after {
        content: '<?= translate('upload_logo', $i18n) ?>';
    }
</style>
<?php page_toolbar_start(translate('profile', $i18n)); ?>
<?php page_toolbar_end(); ?>
<section class="contain settings">
    <section class="account-section">
        <header>
            <h2><?= translate('user_details', $i18n) ?></h2>
        </header>
        <form action="endpoints/user/saveuser.php" method="post" id="userForm" enctype="multipart/form-data">
            <div class="user-form">
                <div class="fields">
                    <div>
                        <div class="user-avatar">
                            <img src="<?= htmlspecialchars($userData['avatar'], ENT_QUOTES, 'UTF-8') ?>" alt="avatar" class="avatar" id="avatarImg"
                                onClick="toggleAvatarSelect()" />
                            <span class="edit-avatar" onClick="toggleAvatarSelect()" title="Change Avatar">
                                <i class="fa-solid fa-pencil"></i>
                            </span>
                        </div>

                        <input type="hidden" name="avatar" value="<?= htmlspecialchars($userData['avatar'], ENT_QUOTES, 'UTF-8') ?>" id="avatarUser" />
                        <div class="avatar-select" id="avatarSelect">
                            <div class="avatar-list">
                                <?php foreach (scandir('images/avatars') as $image): ?>
                                    <?php if (!str_starts_with($image, '.')): ?>
                                        <img src="images/avatars/<?= htmlspecialchars($image) ?>" alt="<?= htmlspecialchars($image) ?>" class="avatar-option"
                                            data-src="images/avatars/<?= htmlspecialchars($image) ?>">
                                    <?php endif ?>
                                <?php endforeach ?>

                                <?php foreach ($uploadedAvatars as $path): ?>
                                    <?php
                                        $filename = basename($path);
                                    ?>
                                    <div class="avatar-container" data-src="<?= htmlspecialchars($filename) ?>">
                                        <img src="<?= htmlspecialchars($path) ?>" alt="<?= htmlspecialchars($filename) ?>"
                                            class="avatar-option" data-src="<?= htmlspecialchars($path) ?>">

                                        <div class="remove-avatar" onclick="deleteAvatar(<?= json_encode($filename, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)"
                                            title="Delete avatar">
                                            <i class="fa-solid fa-xmark"></i>
                                        </div>
                                    </div>
                                <?php endforeach ?>

                                <label for="profile_pic" class="add-avatar"
                                    title="<?= translate('upload_avatar', $i18n) ?>">
                                    <i class="fa-solid fa-arrow-up-from-bracket"></i>
                                </label>
                            </div>
                            
                            <input type="file" id="profile_pic" class="hidden-input" name="profile_pic"
                                accept="image/jpeg, image/png, image/gif, image/webp"
                                onChange="successfulUpload(this, '<?= addslashes(translate('file_type_error', $i18n)) ?>')" />
                        </div>
                    </div>
                    <div class="grow">
                        <div class="form-group">
                            <label for="username"><?= translate('username', $i18n) ?>:</label>
                            <input type="text" id="username" name="username" value="<?= htmlspecialchars($userData['username']) ?>"
                                disabled>
                        </div>
                        <div class="form-group">
                            <label for="firstname"><?= translate('firstname', $i18n) ?>:</label>
                            <input type="text" id="firstname" name="firstname" autocomplete="given-name"
                                value="<?= htmlspecialchars($userData['firstname']) ?>">
                        </div>
                        <div class="form-group">
                            <label for="lastname"><?= translate('lastname', $i18n) ?>:</label>
                            <input type="text" id="lastname" name="lastname" autocomplete="family-name"
                                value="<?= htmlspecialchars($userData['lastname']) ?>">
                        </div>
                        <div class="form-group">
                            <label for="email"><?= translate('email', $i18n) ?>:</label>
                            <input type="email" id="email" name="email" autocomplete="email"
                                value="<?= htmlspecialchars($userData['email']) ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="password"><?= translate('password', $i18n) ?>:</label>
                            <input type="password" id="password" name="password" autocomplete="new-password"
                                <?= $demoMode ? 'disabled title="Not available on Demo Mode"' : '' ?>>
                        </div>
                        <div class="form-group">
                            <label for="confirm_password"><?= translate('confirm_password', $i18n) ?>:</label>
                            <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password"
                                <?= $demoMode ? 'disabled title="Not available on Demo Mode"' : '' ?>>
                        </div>
                        <?php
                        $currencies = array();
                        $query = "SELECT * FROM currencies WHERE user_id = :userId";
                        $query = $db->prepare($query);
                        $query->bindValue(':userId', $userId, SQLITE3_INTEGER);
                        $result = $query->execute();
                        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                            $currencyId = $row['id'];
                            $currencies[$currencyId] = $row;
                        }
                        $userData['currency_symbol'] = "€";
                        ?>
                        <div class="form-group">
                            <label for="currency"><?= translate('main_currency', $i18n) ?>:</label>
                            <select id="currency" name="main_currency" placeholder="Currency">
                                <?php
                                foreach ($currencies as $currency) {
                                    $selected = "";
                                    if ($currency['id'] == $userData['main_currency']) {
                                        $selected = "selected";
                                        $userData['currency_symbol'] = $currency['symbol'];
                                    }
                                    ?>
                                    <option value="<?= $currency['id'] ?>" <?= $selected ?>><?= htmlspecialchars($currency['name']) ?></option>
                                    <?php
                                }
                                ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="language"><?= translate('language', $i18n) ?>:</label>
                            <select id="language" name="language" placeholder="Language">
                                <?php
                                foreach ($languages as $code => $language) {
                                    $selected = ($code === $lang) ? 'selected' : '';
                                    ?>
                                    <option value="<?= $code ?>" <?= $selected ?>><?= $language['name'] ?></option>
                                    <?php
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="buttons">
                    <input type="submit" value="<?= translate('save', $i18n) ?>" id="userSubmit"
                        class="thin mobile-grow" />
                </div>
            </div>
        </form>
    </section>

    <?php
    $sql = "SELECT login_disabled FROM admin";
    $stmt = $db->prepare($sql);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    $loginDisabled = $row['login_disabled'];

    $showTotpSection = true;
    if ($loginDisabled && !$userData['totp_enabled']) {
        $showTotpSection = false;
    }

    if ($showTotpSection) {
        ?>
        <section class="account-section">
            <header>
                <h2><?= translate("two_factor_authentication", $i18n) ?></h2>
            </header>
            <div class="account-2fa">
                <div class="buttons">
                    <?php
                    if (!$userData['totp_enabled']) {
                        ?>
                        <input type="button" value="<?= translate('enable_two_factor_authentication', $i18n) ?>" id="enableTotp"
                            onClick="enableTotp()" class="button thin mobile-grow"/>
                        <div class="totp-popup" id="totp-popup">
                            <header>
                                <h3><?= translate('enable_two_factor_authentication', $i18n) ?></h3>
                                <span class="fa-solid fa-xmark close-form" onclick="closeTotpPopup()"></span>
                            </header>
                            <div class="totp-popup-content">
                                <div class="totp-setup" id="totp-setup">
                                    <div class="totp-qrcode-container">
                                        <div id="totp-qr-code"></div>
                                    </div>
                                    <p class="totp-secret" id="totp-secret-code"></p>
                                    <div class="form-group-inline">
                                        <input type="hidden" name="totp-secret" id="totp-secret" value="" />
                                        <input type="text" id="totp" name="totp" autocomplete="one-time-code"
                                            placeholder="<?= translate("totp_code", $i18n) ?>" />
                                        <input type="button" value="<?= translate('enable', $i18n) ?>" id="enableTotpButton"
                                            onClick="submitTotp()" />
                                    </div>
                                </div>
                                <div class="totp-setup hide" id="totp-backup-codes">
                                    <h4><?= translate('backup_codes', $i18n) ?></h4>
                                    <ul class="totp-backup-codes" id="backup-codes"></ul>
                                    <div class="form-group-inline wrap">
                                        <input type="button" class="button secondary-button grow"
                                            value="<?= translate('copy_to_clipboard', $i18n) ?>" id="copyBackupCodes"
                                            onClick="copyBackupCodes()" />
                                        <input type="button" class="grow"
                                            value="<?= translate('download_backup_codes', $i18n) ?>" id="downloadBackupCodes"
                                            onClick="downloadBackupCodes()" />
                                    </div>
                                    <div class="settings-notes">
                                        <p>
                                            <i class="fa-solid fa-circle-info"></i>
                                            <?= translate('totp_backup_codes_info', $i18n) ?>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php
                    } else {
                        ?>
                        <input type="button" class="button secondary-button thin mobile-grow"
                            value="<?= translate('disable_two_factor_authentication', $i18n) ?>" id="disableTotp"
                            onClick="disableTotp()" />
                        <div class="totp-popup" id="totp-disable-popup">
                            <header>
                                <h3><?= translate('disable_two_factor_authentication', $i18n) ?></h3>
                                <span class="fa-solid fa-xmark close-form" onclick="closeTotpDisablePopup()"></span>
                            </header>
                            <div class="totp-popup-content">
                                <div class="form-group-inline">
                                    <input type="text" id="totp-disable" name="totp-disable" autocomplete="one-time-code"
                                        placeholder="totp" />
                                    <input type="button" value="<?= translate('disable', $i18n) ?>" id="disableTotpButton"
                                        onClick="submitDisableTotp()" />
                                </div>
                            </div>
                        </div>
                        <?php
                    }
                    ?>
                </div>
                <div class="settings-notes">
                    <p>
                        <i class="fa-solid fa-circle-info"></i>
                        <?php
                        if (!$userData['totp_enabled']) {
                            echo translate('two_factor_info', $i18n);
                        } else {
                            echo translate('two_factor_enabled_info', $i18n);
                        }
                        ?>
                    </p>
                </div>
            </div>
        </section>
        <?php
    }

    ?>

    <?php
    // API keys (issue #43): only a hash is stored, so a key is shown once,
    // when it is created.
    require_once 'includes/api_keys.php';
    $apiKeys = wallos_api_list_keys($db, $userId);
    $apiScopeLabels = [
        'read' => translate('api_scope_read', $i18n),
        'write' => translate('api_scope_write', $i18n),
        'ical' => translate('api_scope_ical', $i18n),
        'admin:read' => translate('api_scope_admin_read', $i18n),
    ];
    ?>
    <section class="account-section">
        <header>
            <h2><?= translate('api_keys', $i18n) ?></h2>
        </header>
        <div class="account-api-key">
            <div class="api-key-list" id="apiKeyList">
                <?php if ($apiKeys === []) { ?>
                    <p class="form-hint" id="apiKeyListEmpty"><?= translate('no_api_keys', $i18n) ?></p>
                <?php } ?>
                <?php foreach ($apiKeys as $apiKey) { ?>
                    <div class="api-key-row" data-keyid="<?= (int) $apiKey['id'] ?>">
                        <div class="api-key-details">
                            <strong><?= htmlspecialchars($apiKey['name']) ?></strong>
                            <code><?= htmlspecialchars($apiKey['prefix']) ?>&hellip;</code>
                            <span class="api-key-scopes">
                                <?php foreach ($apiKey['scopes'] as $scope) { ?>
                                    <span class="user-role-badge<?= $scope === 'admin:read' ? ' is-admin-scope' : '' ?>"><?= htmlspecialchars($apiScopeLabels[$scope] ?? $scope) ?></span>
                                <?php } ?>
                            </span>
                            <span class="form-hint">
                                <?= translate('created', $i18n) ?> <?= htmlspecialchars(substr($apiKey['created_at'], 0, 16)) ?> UTC
                                &middot;
                                <?= translate('last_used', $i18n) ?>
                                <?= $apiKey['last_used_at'] ? htmlspecialchars(substr($apiKey['last_used_at'], 0, 16)) . ' UTC' : translate('never', $i18n) ?>
                            </span>
                        </div>
                        <button class="image-button medium" onClick="revokeApiKey(<?= (int) $apiKey['id'] ?>)"
                            title="<?= translate('revoke', $i18n) ?>" aria-label="<?= translate('revoke', $i18n) ?>"
                            data-confirm="<?= htmlspecialchars(translate('revoke_api_key_confirmation', $i18n)) ?>">
                            <i class="fa-solid fa-ban"></i>
                        </button>
                    </div>
                <?php } ?>
            </div>

            <div class="api-key-created hide" id="newApiKeyBox">
                <p><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> <?= translate('api_key_shown_once', $i18n) ?></p>
                <div class="form-group-inline">
                    <input type="text" id="newApiKey" value="" readonly aria-label="<?= translate('api_key', $i18n) ?>">
                    <input type="button" class="secondary-button thin" value="<?= translate('copy_to_clipboard', $i18n) ?>" onClick="copyNewApiKey()" />
                </div>
            </div>

            <h3><?= translate('create_api_key', $i18n) ?></h3>
            <div class="form-group">
                <input type="text" id="newApiKeyName" maxlength="100" autocomplete="off"
                    placeholder="<?= translate('name', $i18n) ?>" aria-label="<?= translate('name', $i18n) ?>">
            </div>
            <fieldset class="chip-fieldset">
                <legend><?= translate('api_key_scopes', $i18n) ?></legend>
                <div class="chip-group" id="newApiKeyScopes">
                    <?php foreach (['read', 'write', 'ical'] as $scope) { ?>
                        <input type="checkbox" class="chip-input" id="api_scope_<?= $scope ?>" value="<?= $scope ?>" <?= $scope === 'read' ? 'checked' : '' ?>>
                        <label class="chip" for="api_scope_<?= $scope ?>"><?= htmlspecialchars($apiScopeLabels[$scope]) ?></label>
                    <?php } ?>
                    <?php if ($isAdmin) { ?>
                        <input type="checkbox" class="chip-input" id="api_scope_admin_read" value="admin:read" onChange="toggleApiKeyReauth()">
                        <label class="chip" for="api_scope_admin_read"><?= htmlspecialchars($apiScopeLabels['admin:read']) ?></label>
                    <?php } ?>
                </div>
            </fieldset>
            <?php if ($isAdmin) { ?>
                <div class="api-key-reauth hide" id="apiKeyReauth">
                    <div class="form-group-inline">
                        <input type="password" id="apiKeyReauthPassword" autocomplete="current-password"
                            placeholder="<?= translate('password', $i18n) ?>" aria-label="<?= translate('password', $i18n) ?>">
                        <?php if ($userData['totp_enabled']) { ?>
                            <input type="text" id="apiKeyReauthTotp" inputmode="numeric" autocomplete="one-time-code" maxlength="6"
                                placeholder="<?= translate('totp_code', $i18n) ?>" aria-label="<?= translate('totp_code', $i18n) ?>">
                        <?php } ?>
                    </div>
                    <p class="form-hint"><?= translate('api_key_admin_read_info', $i18n) ?></p>
                </div>
            <?php } ?>
            <div class="buttons">
                <input type="submit" class="thin mobile-grow" value="<?= translate('create_api_key', $i18n) ?>" id="createApiKeyButton" onClick="createApiKey()" />
            </div>
            <div class="settings-notes">
                <p>
                    <i class="fa-solid fa-circle-info"></i> <?= translate('api_key_info', $i18n) ?>
                </p>
            </div>
        </div>
    </section>

    <section class="account-section">
        <header>
            <h2><?= translate('account', $i18n) ?></h2>
        </header>
        <div class="account-list">
            <div>
                <h3><?= translate('export_subscriptions', $i18n) ?></h3>
                <div class="form-group-inline wrap">
                    <input type="button" value="<?= translate('export_as_json', $i18n) ?>" onClick="exportAsJson()"
                        class="secondary-button thin mobile-grow" id="export-json" <?= $demoMode ? 'disabled title="Not available on Demo Mode"' : '' ?>>
                    <input type="button" value="<?= translate('export_as_csv', $i18n) ?>" onClick="exportAsCsv()"
                        class="secondary-button thin mobile-grow" id="export-csv" <?= $demoMode ? 'disabled title="Not available on Demo Mode"' : '' ?>>
                </div>
            </div>
        </div>
        <div>
            <?php
            if (wallos_can_delete_user($db, $userId) && !$demoMode) {
                ?>
                <h3><?= translate('danger_zone', $i18n) ?></h3>
                <div class="form-group-inline">
                    <input type="button" value="<?= translate('delete_account', $i18n) ?>"
                        onClick="deleteAccount(<?= $userId ?>)" class="warning-button thin mobile-grow" id="delete-account">
                </div>
                <div class="settings-notes">
                    <p>
                        <i class="fa-solid fa-circle-info"></i>
                        <?= translate('delete_account_info', $i18n) ?>
                    </p>
                </div>
                <?php
            }
            ?>
        </div>
    </section>

   

</section>
<script src="scripts/profile.js?<?= $version ?>"></script>
<script src="scripts/theme.js?<?= $version ?>"></script>
<script src="scripts/notifications.js?<?= $version ?>"></script>

<?php
require_once 'includes/footer.php';
?>
