/*
  Subscription details panel, shared by the dashboard, subscriptions and
  calendar pages. How it is laid out is entirely CSS:
    - wide screens on the subscriptions page: an inline column next to the list
    - tablet widths, and the dashboard/calendar: a panel sliding in from the right
    - phones: a full-screen page with a back button
  The actions (mark as paid, clone, delete) live here too so every page that
  shows the panel can use them. Pages that keep a live list (subscriptions)
  define fetchSubscriptions(); everywhere else the page reloads after a change.
*/
let currentDetailsId = null;
let currentDetails = null;
let detailsLastFocus = null;

function detailsStrings() {
  return window.subscriptionLookups.i18n;
}

function detailsFill(template, value) {
  return String(template).replace('%s', value);
}

function showSubscriptionDetails(event, id) {
  if (event && event.currentTarget instanceof HTMLElement) {
    detailsLastFocus = event.currentTarget;
  }
  return loadSubscriptionDetails(id, true);
}

function loadSubscriptionDetails(id, focusPanel) {
  return fetch(`endpoints/subscription/get.php?id=${encodeURIComponent(id)}`)
    .then((response) => {
      if (!response.ok) {
        throw new Error(translate('failed_to_load_subscription'));
      }
      return response.json();
    })
    .then((data) => {
      if (!data || data.error || data === "Error") {
        throw new Error(translate('failed_to_load_subscription'));
      }
      renderSubscriptionDetails(data, focusPanel);
    })
    .catch((error) => {
      console.log(error);
      showErrorMessage(translate('failed_to_load_subscription'));
    });
}

function detailsFormatPrice(price, currencyId) {
  const lookups = window.subscriptionLookups;
  const currency = lookups.currencies[currencyId];
  const value = Number(price);
  if (currency && currency.code) {
    try {
      return new Intl.NumberFormat(window.lang || 'en', { style: 'currency', currency: currency.code }).format(value);
    } catch (e) { /* unknown currency code, fall through */ }
  }
  const symbol = currency ? (currency.symbol || currency.code || '') : '';
  return `${symbol}${value.toFixed(2)}`;
}

function detailsParseDate(dateString) {
  if (!dateString) {
    return null;
  }
  const date = new Date(dateString + "T00:00:00");
  return isNaN(date) ? null : date;
}

function detailsFormatDate(dateString, options) {
  const date = detailsParseDate(dateString);
  if (!date) {
    return dateString || "";
  }
  const format = options || { day: 'numeric', month: 'short', year: 'numeric' };
  try {
    return date.toLocaleDateString(window.lang || 'en', format);
  } catch (e) {
    return date.toLocaleDateString('en', format);
  }
}

function detailsDaysUntil(dateString) {
  const date = detailsParseDate(dateString);
  if (!date) {
    return null;
  }
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  return Math.round((date - today) / 86400000);
}

function detailsBillingCycleText(cycle, frequency) {
  const units = window.subscriptionLookups.cycles[cycle];
  if (!units) {
    return "";
  }
  return Number(frequency) === 1 || Number(cycle) === 5 ? units.one : `${frequency} ${units.many}`;
}

function detailsPerText(subscription) {
  const strings = detailsStrings();
  const cycle = Number(subscription.cycle);
  const frequency = Number(subscription.frequency);
  if (cycle === 5) {
    return strings.one_time.toLowerCase();
  }
  if (frequency === 1 && cycle === 3) {
    return strings.per_month;
  }
  if (frequency === 1 && cycle === 4) {
    return strings.per_year;
  }
  return `${strings.every} ${detailsBillingCycleText(cycle, frequency).toLowerCase()}`;
}

// Letter tiles stand in for missing logos. The hue comes from the name so a
// subscription keeps its colour everywhere it appears (see tile_hue() in PHP).
function detailsTileHue(name) {
  let hash = 0;
  for (const ch of String(name || '')) {
    hash = (hash * 31 + ch.codePointAt(0)) >>> 0;
  }
  return hash % 360;
}

function detailsRenderTile(container, subscription) {
  container.innerHTML = "";
  container.classList.toggle('tile-logo', !!subscription.logo);
  container.style.removeProperty('--tile-hue');
  if (subscription.logo) {
    // When a themed variant exists, render both images; CSS shows only the
    // one matching the current theme (see .logo-theme-variant in styles.css).
    if (subscription.logo_text_color && subscription.logo_variant) {
      const nativeTheme = subscription.logo_text_color === 'dark' ? 'light' : 'dark';
      [['logo', 'logo-theme-original'], ['logo_variant', 'logo-theme-variant']].forEach(([key, className]) => {
        const img = document.createElement('img');
        img.src = "images/uploads/logos/" + subscription[key];
        img.alt = "";
        img.className = className;
        img.dataset.nativeTheme = nativeTheme;
        container.appendChild(img);
      });
    } else {
      const img = document.createElement('img');
      img.src = "images/uploads/logos/" + subscription.logo;
      img.alt = "";
      container.appendChild(img);
    }
  } else {
    container.style.setProperty('--tile-hue', detailsTileHue(subscription.name));
    container.textContent = (subscription.name || "?").trim().charAt(0).toUpperCase();
  }
}

function detailsStatus(subscription) {
  const strings = detailsStrings();
  if (Number(subscription.inactive)) {
    return { text: strings.inactive, tone: '' };
  }
  if (Number(subscription.cycle) === 5) {
    return { text: strings.lifetime_purchase, tone: '' };
  }
  const days = detailsDaysUntil(subscription.next_payment);
  if (days === null) {
    return { text: '', tone: '' };
  }
  if (days < 0 && Number(subscription.auto_renew) !== 1) {
    return { text: detailsFill(strings.overdue_since, detailsFormatDate(subscription.next_payment, { day: 'numeric', month: 'short' })), tone: 'danger' };
  }
  if (days <= 0) {
    return { text: strings.next_payment_today, tone: '' };
  }
  if (days === 1) {
    return { text: strings.next_payment_tomorrow, tone: '' };
  }
  return { text: detailsFill(strings.next_payment_in_days, days), tone: '' };
}

function detailsSetSelectedRow(id) {
  document.querySelectorAll('.list-row[aria-current="true"]').forEach((row) => {
    row.removeAttribute('aria-current');
  });
  if (id !== null) {
    document.querySelectorAll(`.list-row[data-id="${id}"]`).forEach((row) => {
      row.setAttribute('aria-current', 'true');
    });
  }
}

function renderSubscriptionDetails(subscription, focusPanel) {
  const lookups = window.subscriptionLookups;
  const strings = lookups.i18n;
  const panel = document.querySelector('#subscription-details');
  currentDetailsId = Number(subscription.id);
  currentDetails = subscription;

  const isOneTime = Number(subscription.cycle) === 5;
  const isInactive = Number(subscription.inactive) === 1;
  const isManual = Number(subscription.auto_renew) !== 1;

  detailsRenderTile(document.querySelector('#details-logo'), subscription);
  document.querySelector('#details-name').textContent = subscription.name;
  document.querySelector('#details-price').textContent = detailsFormatPrice(subscription.price, subscription.currency_id);
  document.querySelector('#details-billing-cycle').textContent = detailsPerText(subscription);

  const status = detailsStatus(subscription);
  const statusEl = document.querySelector('#details-status');
  statusEl.textContent = status.text;
  statusEl.classList.toggle('is-danger', status.tone === 'danger');

  // Actions
  const renewButton = document.querySelector('#details-renew-button');
  renewButton.classList.toggle('hide', !isManual || isInactive || isOneTime);
  renewButton.onclick = (event) => renewSubscription(event, subscription.id);
  document.querySelector('#details-edit-button').onclick = (event) => detailsEdit(event, subscription.id);
  const cloneButton = document.querySelector('#details-clone-button');
  cloneButton.classList.toggle('hide', typeof openEditSubscription !== 'function');
  cloneButton.onclick = (event) => cloneSubscription(event, subscription.id);
  document.querySelector('#details-export-button').onclick = () => exportCalendar(subscription.id);
  document.querySelector('#details-delete-button').onclick = (event) => deleteSubscription(event, subscription.id);
  const urlButton = document.querySelector('#details-url-button');
  if (subscription.url) {
    urlButton.classList.remove('hide');
    urlButton.href = /^https?:\/\//.test(subscription.url) ? subscription.url : "https://" + subscription.url;
  } else {
    urlButton.classList.add('hide');
  }

  // Payment
  document.querySelector('#details-next-payment-label').textContent = isOneTime ? strings.payment_date : strings.next_payment;
  document.querySelector('#details-next-payment').textContent = detailsFormatDate(subscription.next_payment) || strings.none;
  document.querySelector('#details-cycle').textContent = detailsBillingCycleText(subscription.cycle, subscription.frequency);
  document.querySelector('#details-renewal-item').classList.toggle('hide', isOneTime);
  document.querySelector('#details-renewal').textContent = isManual ? strings.manual_renewal : strings.automatic;
  document.querySelector('#details-start-date').textContent = detailsFormatDate(subscription.start_date) || strings.none;
  document.querySelector('#details-category').textContent = lookups.categories[subscription.category_id] || strings.none;
  document.querySelector('#details-payer').textContent = lookups.members[subscription.payer_user_id] || strings.none;

  const paymentMethod = lookups.paymentMethods[subscription.payment_method_id];
  const paymentIcon = document.querySelector('#details-payment-icon');
  if (paymentMethod) {
    paymentIcon.src = paymentMethod.icon;
    paymentIcon.classList.remove('hide');
    document.querySelector('#details-payment-name').textContent = paymentMethod.name;
  } else {
    paymentIcon.classList.add('hide');
    document.querySelector('#details-payment-name').textContent = strings.none;
  }

  const cancellationItem = document.querySelector('#details-cancellation-item');
  cancellationItem.classList.toggle('hide', !subscription.cancellation_date);
  document.querySelector('#details-cancellation').textContent = detailsFormatDate(subscription.cancellation_date);

  const replacementName = subscription.replacement_subscription_id ? lookups.subscriptionNames[subscription.replacement_subscription_id] : null;
  document.querySelector('#details-replacement-item').classList.toggle('hide', !replacementName);
  document.querySelector('#details-replacement').textContent = replacementName || "";

  // Notifications (one-time purchases never notify)
  document.querySelector('#details-notifications-card').classList.toggle('hide', isOneTime);
  detailsRenderNotify(subscription);

  // Notes: notes_html is rendered server-side through render_notes_markdown()
  // (Parsedown safe mode) - never raw user text.
  const notesItem = document.querySelector('#details-notes-item');
  notesItem.classList.toggle('hide', !subscription.notes);
  document.querySelector('#details-notes').innerHTML = subscription.notes ? subscription.notes_html : "";

  detailsSetSelectedRow(currentDetailsId);
  const wasOpen = panel.classList.contains('is-open');
  panel.classList.add('is-open', 'has-selection');
  document.querySelector('#details-backdrop').classList.add('is-open');
  document.body.classList.add('details-open');
  if (focusPanel && !wasOpen && !detailsIsInline()) {
    panel.scrollTop = 0;
    panel.focus({ preventScroll: true });
  }
}

function detailsRenderNotify(subscription) {
  const strings = detailsStrings();
  const notify = Number(subscription.notify) === 1;
  const toggle = document.querySelector('#details-notify-switch');
  toggle.setAttribute('aria-checked', notify ? 'true' : 'false');
  toggle.onclick = () => detailsSaveNotify(!notify, subscription.notify_days_before);

  const lead = document.querySelector('#details-lead-select');
  const days = subscription.notify_days_before === null || subscription.notify_days_before === "" ? -1 : Number(subscription.notify_days_before);
  if (![...lead.options].some((option) => Number(option.value) === days)) {
    const option = document.createElement('option');
    option.value = days;
    option.textContent = `${days} ${strings.days_before}`;
    lead.appendChild(option);
  }
  lead.value = String(days);
  lead.onchange = () => detailsSaveNotify(true, Number(lead.value));
  document.querySelector('#details-lead-row').classList.toggle('hide', !notify);
  document.querySelector('#details-notify-note').textContent = notify ? strings.reminder_note_on : strings.reminder_note_off;
}

function detailsSaveNotify(notify, daysBefore) {
  if (!currentDetails) {
    return;
  }
  const subscription = currentDetails;
  const previous = { notify: subscription.notify, notify_days_before: subscription.notify_days_before };
  subscription.notify = notify ? 1 : 0;
  subscription.notify_days_before = daysBefore === null || daysBefore === undefined ? -1 : daysBefore;
  detailsRenderNotify(subscription);

  fetch('endpoints/subscription/notifications.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.csrfToken },
    body: JSON.stringify({ id: subscription.id, notify: notify, notify_days_before: subscription.notify_days_before }),
  })
    .then((response) => response.json())
    .then((data) => {
      if (!data.success) {
        throw new Error(data.message);
      }
    })
    .catch((error) => {
      // Put the switch back the way it was.
      Object.assign(subscription, previous);
      if (currentDetails === subscription) {
        detailsRenderNotify(subscription);
      }
      showErrorMessage(error.message || translate('unknown_error'));
    });
}

function detailsIsInline() {
  const panel = document.querySelector('#subscription-details');
  return !!panel && getComputedStyle(panel).position !== 'fixed';
}

function closeSubscriptionDetails() {
  const panel = document.querySelector('#subscription-details');
  if (!panel) {
    return;
  }
  currentDetailsId = null;
  currentDetails = null;
  panel.classList.remove('is-open', 'has-selection');
  document.querySelector('#details-backdrop').classList.remove('is-open');
  document.body.classList.remove('details-open');
  detailsSetSelectedRow(null);
  if (detailsLastFocus && document.body.contains(detailsLastFocus)) {
    detailsLastFocus.focus({ preventScroll: true });
  }
  detailsLastFocus = null;
}

function detailsEdit(event, id) {
  if (typeof openEditSubscription === 'function') {
    openEditSubscription(event, id);
  } else {
    window.location.href = `subscriptions.php?edit=${encodeURIComponent(id)}`;
  }
}

// After a change: refresh the live list where there is one, otherwise reload.
function detailsAfterChange(initiator, id, event) {
  if (typeof fetchSubscriptions === 'function') {
    fetchSubscriptions(id, event, initiator);
  } else {
    window.location.reload();
  }
}

function deleteSubscription(event, id) {
  if (event) {
    event.stopPropagation();
    event.preventDefault();
  }

  if (!confirm(translate('confirm_delete_subscription'))) {
    return;
  }

  fetch("endpoints/subscription/delete.php", {
    method: "POST",
    headers: { "Content-Type": "application/json", "X-CSRF-Token": window.csrfToken },
    body: JSON.stringify({ id: id }),
  })
    .then((response) => response.json())
    .then((data) => {
      if (data.success) {
        showSuccessMessage(translate('subscription_deleted'));
        if (typeof closeAddSubscription === 'function') {
          closeAddSubscription();
        }
        closeSubscriptionDetails();
        detailsAfterChange("delete", null, null);
      } else {
        showErrorMessage(data.message || translate('error_deleting_subscription'));
      }
    })
    .catch((error) => {
      console.error("Error:", error);
      showErrorMessage(translate('error_deleting_subscription'));
    });
}

function cloneSubscription(event, id) {
  if (event) {
    event.stopPropagation();
    event.preventDefault();
  }

  fetch("endpoints/subscription/clone.php", {
    method: "POST",
    headers: { "Content-Type": "application/json", "X-CSRF-Token": window.csrfToken },
    body: JSON.stringify({ id: id }),
  })
    .then((response) => {
      if (!response.ok) {
        throw new Error(translate("network_response_error"));
      }
      return response.json();
    })
    .then((data) => {
      if (data.success) {
        detailsAfterChange("clone", data.id, event);
        showSuccessMessage(decodeURI(data.message));
      } else {
        showErrorMessage(data.message || translate("error"));
      }
    })
    .catch((error) => {
      showErrorMessage(error.message || translate("error"));
    });
}

function renewSubscription(event, id) {
  if (event) {
    event.stopPropagation();
    event.preventDefault();
  }

  fetch("endpoints/subscription/renew.php", {
    method: "POST",
    headers: { "Content-Type": "application/json", "X-CSRF-Token": window.csrfToken },
    body: JSON.stringify({ id: id }),
  })
    .then((response) => {
      if (!response.ok) {
        throw new Error(translate("network_response_error"));
      }
      return response.json();
    })
    .then((data) => {
      if (data.success) {
        showSuccessMessage(decodeURI(data.message));
        detailsAfterChange("renew", id, event);
      } else {
        showErrorMessage(data.message || translate("error"));
      }
    })
    .catch((error) => {
      showErrorMessage(error.message || translate("error"));
    });
}

document.addEventListener('DOMContentLoaded', function () {
  const panel = document.querySelector('#subscription-details');
  if (!panel) {
    return;
  }

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && panel.classList.contains('is-open') && !document.querySelector('.subscription-form.is-open')) {
      closeSubscriptionDetails();
    }
  });
});

function decodeHtmlEntities(str) {
  const txt = document.createElement('textarea');
  txt.innerHTML = str;
  return txt.value;
}

function exportCalendar(subscriptionId) {
  fetch('endpoints/subscription/exportcalendar.php', {
    method: 'POST',
    body: JSON.stringify({ id: subscriptionId }),
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': window.csrfToken,
    }
  })
    .then(response => response.json())
    .then(data => {
      if (data.success && data.ics) {
        const blob = new Blob([data.ics], { type: 'text/calendar' });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        // Use the subscription name for the file name, replacing any characters that are invalid in file names
        a.download = `${decodeHtmlEntities(data.name).replace(/[\/\\:*?"<>|]/g, '_').toLowerCase()}.ics`;
        document.body.appendChild(a);
        a.click();
        window.URL.revokeObjectURL(url);
      } else {
        showErrorMessage(data.message);
      }
    })
    .catch(error => console.error('Error:', error));
}
