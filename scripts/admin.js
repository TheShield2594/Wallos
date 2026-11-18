function makeFetchCall(url, data, button) {
  return fetch(url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': window.csrfToken,
    },
    body: JSON.stringify(data),
  })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        showSuccessMessage(data.message);
      } else {
        showErrorMessage(data.message);
      }
      button.disabled = false;
    })
    .catch((error) => {
      showErrorMessage(error);
      button.disabled = false;
    });

}

function testSmtpSettingsButton() {
  const button = document.getElementById("testSmtpSettingsButton");
  button.disabled = true;

  const smtpAddress = document.getElementById("smtpaddress").value;
  const smtpPort = document.getElementById("smtpport").value;
  const encryption = document.querySelector('input[name="encryption"]:checked').value;
  const smtpUsername = document.getElementById("smtpusername").value;
  const smtpPassword = document.getElementById("smtppassword").value;
  const fromEmail = document.getElementById("fromemail").value;

  const data = {
    smtpaddress: smtpAddress,
    smtpport: smtpPort,
    encryption: encryption,
    smtpusername: smtpUsername,
    smtppassword: smtpPassword,
    fromemail: fromEmail
  };

  makeFetchCall('endpoints/notifications/testemailnotifications.php', data, button);
}

function saveSmtpSettingsButton() {
  const button = document.getElementById("saveSmtpSettingsButton");
  button.disabled = true;

  const smtpAddress = document.getElementById("smtpaddress").value;
  const smtpPort = document.getElementById("smtpport").value;
  const encryption = document.querySelector('input[name="encryption"]:checked').value;
  const smtpUsername = document.getElementById("smtpusername").value;
  const smtpPassword = document.getElementById("smtppassword").value;
  const fromEmail = document.getElementById("fromemail").value;

  const data = {
    smtpaddress: smtpAddress,
    smtpport: smtpPort,
    encryption: encryption,
    smtpusername: smtpUsername,
    smtppassword: smtpPassword,
    fromemail: fromEmail
  };

  fetch('endpoints/admin/savesmtpsettings.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': window.csrfToken,
    },
    body: JSON.stringify(data),
  })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        const emailVerificationCheckbox = document.getElementById('requireEmail');
        emailVerificationCheckbox.disabled = false;
        showSuccessMessage(data.message);
      } else {
        showErrorMessage(data.message);
      }
      button.disabled = false;
    })
    .catch((error) => {
      showErrorMessage(error);
      button.disabled = false;
    });

}

function backupDB() {
  const button = document.getElementById("backupDB");
  button.disabled = true;

  fetch("endpoints/db/backup.php", {
    method: "POST",
    headers: {
      "X-CSRF-Token": window.csrfToken,
    },
  })
    .then(async response => {
      const contentType = response.headers.get("content-type") || "";
      // Success streams the zip; any failure returns a JSON error body.
      if (!response.ok || !contentType.includes("application/zip")) {
        const data = await response.json().catch(() => ({}));
        showErrorMessage(data.message || translate("backup_failed"));
        return;
      }

      const blob = await response.blob();
      const url = URL.createObjectURL(blob);
      const link = document.createElement("a");
      link.href = url;

      const date = new Date();
      const year = date.getFullYear();
      const month = String(date.getMonth() + 1).padStart(2, "0");
      const day = String(date.getDate()).padStart(2, "0");
      const hours = String(date.getHours()).padStart(2, "0");
      const minutes = String(date.getMinutes()).padStart(2, "0");
      const timestamp = `${year}${month}${day}-${hours}${minutes}`;
      link.download = `Tally-Backup-${timestamp}.zip`;

      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      URL.revokeObjectURL(url);
    })
    .catch(error => {
      console.error(error);
      showErrorMessage(translate("unknown_error"));
    })
    .finally(() => {
      button.disabled = false;
    });
}


function openRestoreDBFileSelect() {
  document.getElementById('restoreDBFile').click();
};

function restoreDB() {
  const input = document.getElementById('restoreDBFile');
  const file = input.files[0];

  if (!file) {
    showErrorMessage(translate('no_file_selected'));
    return;
  }

  const formData = new FormData();
  formData.append('file', file);

  const button = document.getElementById('restoreDB');
  button.disabled = true;

  fetch('endpoints/db/restore.php', {
    method: 'POST',
    headers: {
      'X-CSRF-Token': window.csrfToken, // ✅ CSRF protection
    },
    body: formData,
  })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        showSuccessMessage(data.message);

        // After restoring, run migrations then log out (force re-login)
        fetch('endpoints/db/migrate.php')
          .then(() => {
            window.location.href = 'logout.php';
          })
          .catch(() => {
            window.location.href = 'logout.php';
          });
      } else {
        showErrorMessage(data.message || translate('restore_failed'));
      }
    })
    .catch(error => {
      console.error(error);
      showErrorMessage(translate('unknown_error'));
    })
    .finally(() => {
      button.disabled = false;
    });
}

function saveAccountRegistrationsButton() {
  const button = document.getElementById('saveAccountRegistrations');
  button.disabled = true;

  const open_registrations = document.getElementById('registrations').checked ? 1 : 0;
  const max_users = document.getElementById('maxUsers').value;
  const require_email_validation = document.getElementById('requireEmail').checked ? 1 : 0;
  const server_url = document.getElementById('serverUrl').value;
  const disable_login = document.getElementById('disableLogin').checked ? 1 : 0;

  const data = {
    open_registrations: open_registrations,
    max_users: max_users,
    require_email_validation: require_email_validation,
    server_url: server_url,
    disable_login: disable_login
  };

  fetch('endpoints/admin/saveopenregistrations.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': window.csrfToken,
    },
    body: JSON.stringify(data)
  })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        showSuccessMessage(data.message);
        button.disabled = false;
      } else {
        showErrorMessage(data.message);
        button.disabled = false;
      }
    })
    .catch(error => {
      showErrorMessage(error);
      button.disabled = false;
    });
}

function saveSecuritySettingsButton() {
  const button = document.getElementById('saveSecuritySettingsButton');
  button.disabled = true;

  const allowlist = document.getElementById('local_webhook_notifications_allowlist').value;
  const allowStandardUsers = document.getElementById('allow_standard_users_local_webhooks').checked;

  const data = {
    local_webhook_notifications_allowlist: allowlist,
    allow_standard_users_local_webhooks: allowStandardUsers
  };

  fetch('endpoints/admin/savesecuritysettings.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': window.csrfToken,
    },
    body: JSON.stringify(data)
  })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        showSuccessMessage(data.message);
        button.disabled = false;
      } else {
        showErrorMessage(data.message);
        button.disabled = false;
      }
    })
    .catch(error => {
      showErrorMessage(error);
      button.disabled = false;
    });
}

function removeUser(userId) {
  const data = {
    userId: userId
  };

  fetch('endpoints/admin/deleteuser.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': window.csrfToken,
    },
    body: JSON.stringify(data)
  })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        showSuccessMessage(data.message);
        const userContainer = document.querySelector(`.form-group-inline[data-userid="${userId}"]`);
        if (userContainer) {
          userContainer.remove();
        }
      } else {
        showErrorMessage(data.message);
      }
    })
    .catch(error => showErrorMessage('Error:', error));

}

function setUserAdmin(userId, isAdmin) {
  fetch('endpoints/admin/setuseradmin.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': window.csrfToken,
    },
    body: JSON.stringify({ userId: userId, isAdmin: isAdmin })
  })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        showSuccessMessage(data.message);
        // The badges, buttons and last-admin rules all depend on the new role.
        setTimeout(() => window.location.reload(), 600);
      } else {
        showErrorMessage(data.message);
      }
    })
    .catch(error => showErrorMessage('Error:', error));
}

function adminRevokeApiKey(keyId) {
  const row = document.querySelector(`.api-key-row[data-keyid="${keyId}"]`);
  const button = row ? row.querySelector("button") : null;
  if (button && button.dataset.confirm && !confirm(button.dataset.confirm)) {
    return;
  }

  fetch('endpoints/admin/revokeapikey.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': window.csrfToken,
    },
    body: JSON.stringify({ id: keyId })
  })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        if (row) {
          row.remove();
        }
        showSuccessMessage(data.message);
      } else {
        showErrorMessage(data.message);
      }
    })
    .catch(error => showErrorMessage('Error:', error));
}

function addUserButton() {
  const button = document.getElementById('addUserButton');
  button.disabled = true;

  const username = document.getElementById('newUsername').value;
  const email = document.getElementById('newEmail').value;
  const password = document.getElementById('newPassword').value;

  const data = {
    username: username,
    email: email,
    password: password
  };

  fetch('endpoints/admin/adduser.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': window.csrfToken,
    },
    body: JSON.stringify(data)
  })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        showSuccessMessage(data.message);
        button.disabled = false;
        window.location.reload();
      } else {
        showErrorMessage(data.message);
        button.disabled = false;
      }
    })
    .catch(error => {
      showErrorMessage(error);
      button.disabled = false;
    });
}

function deleteUnusedLogos() {
  const button = document.getElementById('deleteUnusedLogos');
  button.disabled = true;

  fetch('endpoints/admin/deleteunusedlogos.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': window.csrfToken,
    }
  })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        showSuccessMessage(data.message);
        const numberOfLogos = document.querySelector('.number-of-logos');
        numberOfLogos.innerText = '0';
      } else {
        showErrorMessage(data.message);
        button.disabled = false;
      }
    })
    .catch(error => {
      showErrorMessage(error);
      button.disabled = false;
    });
}

function toggleUpdateNotification() {
  const notificationEnabledCheckbox = document.getElementById('updateNotification');
  const notificationEnabled = notificationEnabledCheckbox.checked ? 1 : 0;

  const data = {
    notificationEnabled: notificationEnabled
  };

  fetch('endpoints/admin/updatenotification.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': window.csrfToken,
    },
    body: JSON.stringify(data)
  })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        showSuccessMessage(data.message);
        if (notificationEnabled === 1) {
          fetch('endpoints/cronjobs/checkforupdates.php');
        }
      } else {
        showErrorMessage(data.message);
      }
    })
    .catch(error => showErrorMessage('Error:', error));

}

function executeCronJob(job) {
  const url = `endpoints/cronjobs/${job}.php`;
  const resultTextArea = document.getElementById('cronjobResult');

  fetch(url)
    .then(response => {
      return response.text();
    })
    .then(data => {
      const formattedData = data.replace(/<br\s*\/?>/gi, '\n');
      resultTextArea.value = formattedData;
    })
    .catch(error => {
      console.error('Fetch error:', error);
      showErrorMessage('Error:', error);
    });
}

function toggleOidcEnabled() {
  const toggle = document.getElementById("oidcEnabled");
  toggle.disabled = true;

  const oidcEnabled = toggle.checked ? 1 : 0;

  const data = {
    oidcEnabled: oidcEnabled
  };

  fetch('endpoints/admin/enableoidc.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': window.csrfToken,
    },
    body: JSON.stringify(data)
  })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        showSuccessMessage(data.message);
      } else {
        showErrorMessage(data.message);
      }
      toggle.disabled = false;
    })
    .catch(error => {
      showErrorMessage('Error:', error);
      toggle.disabled = false;
    });

}

function saveOidcSettingsButton() {
  const button = document.getElementById("saveOidcSettingsButton");
  button.disabled = true;

  const oidcName = document.getElementById("oidcName").value;
  const oidcClientId = document.getElementById("oidcClientId").value;
  const oidcClientSecret = document.getElementById("oidcClientSecret").value;
  const oidcAuthUrl = document.getElementById("oidcAuthUrl").value;
  const oidcTokenUrl = document.getElementById("oidcTokenUrl").value;
  const oidcUserInfoUrl = document.getElementById("oidcUserInfoUrl").value;
  const oidcRedirectUrl = document.getElementById("oidcRedirectUrl").value;
  const oidcLogoutUrl = document.getElementById("oidcLogoutUrl").value;
  const oidcUserIdentifierField = document.getElementById("oidcUserIdentifierField").value;
  const oidcScopes = document.getElementById("oidcScopes").value;
  const oidcAuthStyle = document.getElementById("oidcAuthStyle").value;
  const oidcAutoCreateUser = document.getElementById("oidcAutoCreateUser").checked ? 1 : 0;
  const oidcPasswordLoginDisabled = document.getElementById("oidcPasswordLoginDisabled").checked ? 1 : 0;
  const oidcRequireEmailVerified = document.getElementById("oidcRequireEmailVerified").checked ? 1 : 0;
  const oidcRolesClaim = document.getElementById("oidcRolesClaim").value;
  const oidcAdminValues = document.getElementById("oidcAdminValues").value;

  // Turning password login off locks out every account without an OIDC link.
  const passwordLoginCheckbox = document.getElementById("oidcPasswordLoginDisabled");
  if (oidcPasswordLoginDisabled === 1 && passwordLoginCheckbox.dataset.wasChecked !== "1"
    && !confirm(passwordLoginCheckbox.dataset.confirm)) {
    button.disabled = false;
    return;
  }

  const data = {
    oidcName: oidcName,
    oidcClientId: oidcClientId,
    oidcClientSecret: oidcClientSecret,
    oidcAuthUrl: oidcAuthUrl,
    oidcTokenUrl: oidcTokenUrl,
    oidcUserInfoUrl: oidcUserInfoUrl,
    oidcRedirectUrl: oidcRedirectUrl,
    oidcLogoutUrl: oidcLogoutUrl,
    oidcUserIdentifierField: oidcUserIdentifierField,
    oidcScopes: oidcScopes,
    oidcAuthStyle: oidcAuthStyle,
    oidcAutoCreateUser: oidcAutoCreateUser,
    oidcPasswordLoginDisabled: oidcPasswordLoginDisabled,
    oidcRequireEmailVerified: oidcRequireEmailVerified,
    oidcRolesClaim: oidcRolesClaim,
    oidcAdminValues: oidcAdminValues
  };


  fetch('endpoints/admin/saveoidcsettings.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': window.csrfToken,
    },
    body: JSON.stringify(data)
  })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        showSuccessMessage(data.message);
      } else {
        showErrorMessage(data.message);
      }
      button.disabled = false;
    })
    .catch(error => {
      showErrorMessage('Error:', error);
      button.disabled = false;
    });
}
// Default categories for new accounts (issue #31). Each change reloads the
// list, since a new parent changes which rows can take sub-categories.
function categoryTemplateRequest(data, button) {
  if (button) {
    button.disabled = true;
  }
  return fetch('endpoints/admin/category_templates.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': window.csrfToken,
    },
    body: JSON.stringify(data),
  })
    .then(response => response.json())
    .then(result => {
      if (result.success) {
        showSuccessMessage(result.message);
        setTimeout(() => window.location.reload(), 700);
      } else {
        showErrorMessage(result.message);
      }
      if (button) {
        button.disabled = false;
      }
    })
    .catch((error) => {
      showErrorMessage(error);
      if (button) {
        button.disabled = false;
      }
    });
}

function addCategoryTemplate(button) {
  const row = document.getElementById('newCategoryTemplate');
  categoryTemplateRequest({
    action: 'add',
    name: row.querySelector('input[name="name"]').value,
    parent_id: row.querySelector('select[name="parent"]').value || null,
  }, button);
}

function saveCategoryTemplate(id) {
  const row = document.querySelector(`[data-templateid="${id}"]`);
  const parent = row.querySelector('select[name="parent"]');
  categoryTemplateRequest({
    action: 'edit',
    id: id,
    name: row.querySelector('input[name="name"]').value,
    parent_id: parent.disabled ? null : (parent.value || null),
  }, row.querySelector('button[name="save"]'));
}

function deleteCategoryTemplate(id) {
  categoryTemplateRequest({ action: 'delete', id: id }, null);
}

function applyCategoryTemplates(button) {
  categoryTemplateRequest({ action: 'apply' }, button);
}
