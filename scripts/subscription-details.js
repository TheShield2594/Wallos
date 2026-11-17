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
  if (subscription.ended_reason === 'completed') {
    return { text: strings.plan_completed_label, tone: '' };
  }
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
  if (subscription.paid_badge) {
    return { text: detailsFill(strings.paid_badge, detailsFormatDate(subscription.paid_badge, { day: 'numeric', month: 'short' })), tone: 'success' };
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
  // During a free trial or promo the header shows what is charged now.
  const headerPrice = subscription.intro_active ? subscription.current_price : subscription.price;
  document.querySelector('#details-price').textContent = detailsFormatPrice(headerPrice, subscription.currency_id);
  document.querySelector('#details-billing-cycle').textContent = detailsPerText(subscription);

  const status = detailsStatus(subscription);
  const statusEl = document.querySelector('#details-status');
  statusEl.textContent = status.text;
  statusEl.classList.toggle('is-danger', status.tone === 'danger');
  statusEl.classList.toggle('is-success', status.tone === 'success');
  if (Number(subscription.variable_amount) === 1) {
    document.querySelector('#details-billing-cycle').textContent += ` · ${strings.estimated}`;
  }

  // Household sharing: someone else's subscription is read-only for a
  // viewer; an editor may edit it and mark it as paid. Cloning, deleting and
  // the reminders stay with its owner.
  const access = subscription.access || 'owner';
  const isOwner = access === 'owner';
  const canEdit = access !== 'viewer';
  const sharedLine = document.querySelector('#details-shared');
  let sharedText = '';
  if (!isOwner) {
    sharedText = detailsFill(strings.shared_by, subscription.shared_owner_name || '') + (canEdit ? '' : ` · ${strings.view_only}`);
  } else if (subscription.shared) {
    sharedText = strings.shared_with_household;
  }
  sharedLine.textContent = sharedText;
  sharedLine.classList.toggle('hide', sharedText === '');

  // Actions: every active recurring subscription can be marked as paid.
  const renewButton = document.querySelector('#details-renew-button');
  renewButton.classList.toggle('hide', isInactive || isOneTime || !canEdit);
  renewButton.onclick = (event) => renewSubscription(event, subscription.id, subscription);
  const undoButton = document.querySelector('#details-undo-button');
  undoButton.classList.toggle('hide', !subscription.paid_due_date || isInactive || !canEdit);
  undoButton.onclick = (event) => undoPayment(event, subscription.id, subscription.paid_due_date);
  const editButton = document.querySelector('#details-edit-button');
  editButton.classList.toggle('hide', !canEdit);
  editButton.onclick = (event) => detailsEdit(event, subscription.id);
  const cloneButton = document.querySelector('#details-clone-button');
  cloneButton.classList.toggle('hide', typeof openEditSubscription !== 'function' || !isOwner);
  document.querySelector('#details-delete-button').classList.toggle('hide', !isOwner);
  document.querySelector('#details-export-button').classList.toggle('hide', !isOwner);
  document.querySelector('#details-notifications-card').classList.toggle('hide', !isOwner);
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
  const introItem = document.querySelector('#details-intro-item');
  introItem.classList.toggle('hide', !subscription.intro_active);
  if (subscription.intro_active) {
    document.querySelector('#details-intro-label').textContent = subscription.intro_type === 'trial' ? strings.free_trial : strings.intro_price;
    document.querySelector('#details-intro').textContent = strings.intro_then
      .replace('%1$s', detailsFormatDate(subscription.intro_end_date))
      .replace('%2$s', detailsFormatPrice(subscription.price, subscription.currency_id));
  }
  const planItem = document.querySelector('#details-plan-item');
  const planTotal = Number(subscription.total_payments) || 0;
  planItem.classList.toggle('hide', !planTotal && !subscription.end_date);
  if (subscription.ended_reason === 'completed') {
    document.querySelector('#details-plan').textContent = planTotal
      ? `${strings.plan_completed_label} · ${detailsFill(strings.n_payments, planTotal)}`
      : strings.plan_completed_label;
  } else if (planTotal || subscription.end_date) {
    const current = Math.min(Number(subscription.payments_made) + 1, planTotal);
    const parts = [];
    if (planTotal) {
      parts.push(strings.payment_n_of_total.replace('%s', current).replace('%s', planTotal));
    }
    if (subscription.end_date) {
      parts.push(detailsFill(strings.ends_on, detailsFormatDate(subscription.end_date)));
    }
    document.querySelector('#details-plan').textContent = parts.join(' · ');
  }
  document.querySelector('#details-start-date').textContent = detailsFormatDate(subscription.start_date) || strings.none;
  // A shared subscription carries its owner's names; the ids are not ours.
  const shared = (subscription.access || 'owner') !== 'owner';
  document.querySelector('#details-category').textContent = (shared ? subscription.category_name : lookups.categories[subscription.category_id]) || strings.none;
  document.querySelector('#details-payer').textContent = (shared ? subscription.payer_name : lookups.members[subscription.payer_user_id]) || strings.none;

  const paymentMethod = shared
    ? (subscription.payment_method_name ? { name: subscription.payment_method_name, icon: '' } : null)
    : lookups.paymentMethods[subscription.payment_method_id];
  const paymentIcon = document.querySelector('#details-payment-icon');
  if (paymentMethod) {
    paymentIcon.src = paymentMethod.icon;
    paymentIcon.classList.toggle('hide', !paymentMethod.icon);
    document.querySelector('#details-payment-name').textContent = paymentMethod.name;
  } else {
    paymentIcon.classList.add('hide');
    document.querySelector('#details-payment-name').textContent = strings.none;
  }

  // Cancellation notice: a fixed reminder date, or the date to cancel by
  // before the next renewal, and the minimum term while it still binds.
  const cancellationItem = document.querySelector('#details-cancellation-item');
  cancellationItem.classList.toggle('hide', !subscription.cancel_by);
  document.querySelector('#details-cancellation-label').textContent = subscription.cancel_renewal
    ? strings.cancel_by_label : strings.cancellation_notification;
  document.querySelector('#details-cancellation').textContent = detailsFormatDate(subscription.cancel_by);
  const minTermItem = document.querySelector('#details-min-term-item');
  minTermItem.classList.toggle('hide', !subscription.locked_until);
  document.querySelector('#details-min-term').textContent = subscription.locked_until
    ? detailsFill(strings.locked_in_until, detailsFormatDate(subscription.locked_until)) : '';

  const replacementName = subscription.replacement_subscription_id ? lookups.subscriptionNames[subscription.replacement_subscription_id] : null;
  document.querySelector('#details-replacement-item').classList.toggle('hide', !replacementName);
  document.querySelector('#details-replacement').textContent = replacementName || "";

  detailsLoadHistory(subscription);
  detailsLoadPriceChanges(subscription);

  const tierItem = document.querySelector('#details-tier-item');
  if (tierItem) {
    tierItem.classList.toggle('hide', !subscription.tier_name);
    document.querySelector('#details-tier').textContent = subscription.tier_name || "";
  }

  // Notifications (one-time purchases never notify)
  document.querySelector('#details-notifications-card').classList.toggle('hide', isOneTime || (subscription.access || 'owner') !== 'owner');
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

function detailsPost(url, body) {
  return fetch(url, {
    method: "POST",
    headers: { "Content-Type": "application/json", "X-CSRF-Token": window.csrfToken },
    body: JSON.stringify(body),
  }).then((response) => {
    if (!response.ok) {
      throw new Error(translate("network_response_error"));
    }
    return response.json();
  });
}

// Asks for the amount actually paid. Returns undefined when cancelled, and
// null to keep the default.
// currencyId names the currency the amount is entered in (shown in the
// prompt); a household member enters it in the currency they are shown.
function detailsAskAmount(defaultAmount, currencyId) {
  const strings = detailsStrings();
  const currency = currencyId !== undefined ? window.subscriptionLookups.currencies[currencyId] : null;
  const label = currency && currency.code ? `${strings.enter_amount_paid} (${currency.code})` : strings.enter_amount_paid;
  const answer = window.prompt(label, defaultAmount === null || defaultAmount === undefined ? "" : String(defaultAmount));
  if (answer === null) {
    return undefined;
  }
  const normalized = answer.trim().replace(',', '.');
  if (normalized === "") {
    return null;
  }
  const amount = Number(normalized);
  if (!isFinite(amount) || amount < 0) {
    showErrorMessage(strings.invalid_amount);
    return undefined;
  }
  return amount;
}

// "Mark paid": manual renewals move on a cycle, automatic ones confirm the
// latest cycle. Variable-amount subscriptions ask for what was paid.
function renewSubscription(event, id, subscription) {
  if (event) {
    event.stopPropagation();
    event.preventDefault();
  }

  const body = { id: id };
  if (subscription && Number(subscription.variable_amount) === 1) {
    const amount = detailsAskAmount(subscription.price, subscription.currency_id);
    if (amount === undefined) {
      return;
    }
    if (amount !== null) {
      body.amount = amount;
      // The currency the amount was entered in; the server refuses it if a
      // shared subscription is shown in another currency by now.
      body.currency_id = subscription.currency_id;
    }
  }

  detailsPost("endpoints/subscription/markpaid.php", body)
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

function undoPayment(event, id, dueDate) {
  if (event) {
    event.stopPropagation();
    event.preventDefault();
  }
  detailsPost("endpoints/subscription/undopaid.php", { id: id, due_date: dueDate })
    .then((data) => {
      if (data.success) {
        showSuccessMessage(data.message);
        detailsAfterChange("renew", id, event);
      } else {
        showErrorMessage(data.message || translate("error"));
      }
    })
    .catch((error) => {
      showErrorMessage(error.message || translate("error"));
    });
}

// Payment history card: the last 12 ledger rows and a sparkline of the amounts.
function detailsLoadHistory(subscription) {
  const card = document.querySelector('#details-history-card');
  if (!card) {
    return;
  }
  const isOneTime = Number(subscription.cycle) === 5;
  card.classList.toggle('hide', isOneTime);
  if (isOneTime) {
    return;
  }
  const requestedId = Number(subscription.id);
  fetch(`endpoints/ledger/list.php?id=${encodeURIComponent(subscription.id)}`)
    .then((response) => response.json())
    .then((data) => {
      if (currentDetailsId !== requestedId) {
        return;
      }
      detailsRenderHistory(subscription, data && data.success ? data.payments : []);
    })
    .catch(() => {
      if (currentDetailsId === requestedId) {
        detailsRenderHistory(subscription, []);
      }
    });
}

function detailsRenderHistory(subscription, payments) {
  const strings = detailsStrings();
  const list = document.querySelector('#details-history-list');
  const empty = document.querySelector('#details-history-empty');
  list.innerHTML = "";
  empty.classList.toggle('hide', payments.length > 0);

  payments.forEach((payment) => {
    const item = document.createElement('li');
    item.className = 'history-row';

    const text = document.createElement('span');
    text.className = 'history-text';
    const date = document.createElement('span');
    date.className = 'history-date';
    date.textContent = detailsFormatDate(payment.due_date);
    const status = document.createElement('span');
    status.className = `history-status is-${payment.status}`;
    status.textContent = strings[`status_${payment.status}`] || payment.status;
    text.append(date, status);
    if (payment.note) {
      const note = document.createElement('span');
      note.className = 'history-note';
      note.textContent = payment.note;
      text.append(note);
    }

    const amount = document.createElement('span');
    amount.className = 'history-amount';
    const isOwner = (subscription.access || 'owner') === 'owner';
    // For a household member the server converted each payment and set its
    // currency_id to the member's currency it is shown in.
    amount.textContent = payment.amount === null ? '—'
      : detailsFormatPrice(payment.amount, payment.currency_id || subscription.currency_id);

    const edit = document.createElement('button');
    edit.type = 'button';
    edit.className = 'icon-button';
    edit.title = strings.edit;
    edit.setAttribute('aria-label', strings.edit);
    edit.innerHTML = '<i class="fa-solid fa-pen" aria-hidden="true"></i>';
    edit.onclick = () => detailsEditPayment(subscription, payment);

    const remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'icon-button';
    remove.title = strings.delete;
    remove.setAttribute('aria-label', strings.delete);
    remove.innerHTML = '<i class="fa-solid fa-trash" aria-hidden="true"></i>';
    remove.onclick = () => detailsDeletePayment(subscription, payment);

    // The history is edited by the subscription's owner only.
    item.append(text, amount);
    if (isOwner) {
      item.append(edit, remove);
    }
    list.appendChild(item);
  });

  // Oldest on the left. Rows without an amount are skipped.
  const values = payments.filter((payment) => payment.amount !== null).map((payment) => Number(payment.amount)).reverse();
  const sparkline = document.querySelector('#details-history-sparkline');
  sparkline.classList.toggle('hide', values.length < 2);
  if (values.length >= 2) {
    const min = Math.min(...values);
    const max = Math.max(...values);
    const span = max - min || 1;
    const points = values.map((value, index) => {
      const x = (index / (values.length - 1)) * 236 + 2;
      const y = 44 - ((value - min) / span) * 40;
      return `${x.toFixed(1)},${y.toFixed(1)}`;
    });
    sparkline.querySelector('polyline').setAttribute('points', points.join(' '));
  }
}

function detailsEditPayment(subscription, payment) {
  const amount = detailsAskAmount(payment.amount === null ? subscription.price : payment.amount, payment.currency_id || subscription.currency_id);
  if (amount === undefined) {
    return;
  }
  const value = amount === null ? (payment.amount === null ? Number(subscription.price) : payment.amount) : amount;
  const today = new Date();
  const todayString = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
  detailsPost("endpoints/ledger/save.php", {
    id: payment.id,
    amount: value,
    paid_date: payment.paid_date || todayString,
    note: payment.note || "",
  })
    .then((data) => {
      if (!data.success) {
        throw new Error(data.message);
      }
      showSuccessMessage(data.message);
      detailsAfterChange("renew", subscription.id, null);
    })
    .catch((error) => showErrorMessage(error.message || detailsStrings().error));
}

// Dashboard "Payments to review": enter the amount of a variable bill, or
// confirm a renewal the cron recorded at the list price.
function confirmLedgerPayment(event, paymentId, defaultAmount, askAmount) {
  if (event) {
    event.stopPropagation();
    event.preventDefault();
  }
  let amount = defaultAmount;
  if (askAmount) {
    const answer = detailsAskAmount(defaultAmount);
    if (answer === undefined || answer === null) {
      return;
    }
    amount = answer;
  }
  const today = new Date();
  const todayString = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
  detailsPost("endpoints/ledger/save.php", { id: paymentId, amount: amount, paid_date: todayString, note: "" })
    .then((data) => {
      if (!data.success) {
        throw new Error(data.message);
      }
      showSuccessMessage(data.message);
      detailsAfterChange("renew", null, null);
    })
    .catch((error) => showErrorMessage(error.message || detailsStrings().error));
}

function detailsDeletePayment(subscription, payment) {
  if (!confirm(detailsStrings().confirm_delete_payment)) {
    return;
  }
  detailsPost("endpoints/ledger/delete.php", { id: payment.id })
    .then((data) => {
      if (!data.success) {
        throw new Error(data.message);
      }
      showSuccessMessage(data.message);
      detailsAfterChange("renew", subscription.id, null);
    })
    .catch((error) => showErrorMessage(error.message || detailsStrings().error));
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

// Price and plan changes: a timeline of what the subscription used to cost
// (see includes/price_changes.php). The owner's history only.
function detailsLoadPriceChanges(subscription) {
  const card = document.querySelector('#details-price-changes-card');
  if (!card) {
    return;
  }
  card.classList.add('hide');
  if ((subscription.access || 'owner') !== 'owner') {
    return;
  }
  const requestedId = Number(subscription.id);
  fetch(`endpoints/subscription/pricechanges.php?id=${encodeURIComponent(subscription.id)}`)
    .then((response) => response.json())
    .then((data) => {
      if (currentDetailsId !== requestedId || !data || !data.success) {
        return;
      }
      detailsRenderPriceChanges(data.changes || []);
    })
    .catch(() => { /* the card just stays hidden */ });
}

function detailsRenderPriceChanges(changes) {
  const strings = detailsStrings();
  const card = document.querySelector('#details-price-changes-card');
  const list = document.querySelector('#details-price-changes-list');
  list.innerHTML = "";
  card.classList.toggle('hide', changes.length === 0);

  const priceText = (price, currencyId, cycle, frequency) => {
    const cycleText = detailsBillingCycleText(cycle, frequency);
    return detailsFormatPrice(price, currencyId) + (cycleText ? ` · ${cycleText.toLowerCase()}` : '');
  };

  changes.forEach((change) => {
    const item = document.createElement('li');
    item.className = 'history-row';

    const text = document.createElement('span');
    text.className = 'history-text';
    const date = document.createElement('span');
    date.className = 'history-date';
    date.textContent = detailsFormatDate(change.changed_at);
    const status = document.createElement('span');
    status.className = `history-status is-${change.reason === 'provider' ? 'provider' : 'tier'}`;
    status.textContent = change.reason === 'provider' ? strings.price_change_provider : strings.price_change_tier;
    text.append(date, status);

    const detail = document.createElement('span');
    detail.className = 'history-note';
    const tiers = (change.old_tier || change.new_tier) && change.old_tier !== change.new_tier
      ? `${change.old_tier || '—'} → ${change.new_tier || '—'} · ` : '';
    detail.textContent = tiers + priceText(change.old_price, change.old_currency_id, change.old_cycle, change.old_frequency)
      + ' → ' + priceText(change.new_price, change.currency_id, change.new_cycle, change.new_frequency);
    text.append(detail);

    const amount = document.createElement('span');
    amount.className = 'history-amount';
    if (change.old_monthly !== null && change.new_monthly !== null) {
      const delta = change.new_monthly - change.old_monthly;
      const sign = delta > 0 ? '+' : (delta < 0 ? '−' : '');
      amount.textContent = `${sign}${detailsFormatPrice(Math.abs(delta), change.currency_id)}${strings.per_month_short}`;
      amount.classList.toggle('is-success', delta < 0);
      amount.classList.toggle('is-danger', delta > 0);
    } else {
      amount.textContent = '—';
    }

    item.append(text, amount);
    list.appendChild(item);
  });
}
