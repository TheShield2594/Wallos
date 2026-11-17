let scrollTopBeforeOpening = 0;
const shouldScroll = window.innerWidth <= 768;

function setFormOpen(open) {
  const modal = document.getElementById('subscription-form');
  modal.classList.toggle("is-open", open);
  document.querySelector('#subscription-form-backdrop').classList.toggle('is-open', open);
  document.body.classList.toggle('no-scroll', open);
  if (open) {
    const firstField = modal.querySelector('#name');
    setTimeout(() => firstField && firstField.focus({ preventScroll: true }), 50);
  }
}

function toggleNotificationDays() {
  const notifyCheckbox = document.querySelector("#notifications");
  const notifyDaysBefore = document.querySelector("#notify_days_before");
  notifyDaysBefore.disabled = !notifyCheckbox.checked;
}

function resetForm() {
  const id = document.querySelector("#id");
  id.value = "";
  const formTitle = document.querySelector("#form-title");
  formTitle.textContent = translate('add_subscription');
  const logo = document.querySelector("#form-logo");
  logo.src = "";
  logo.style = 'display: none';
  const logoUrl = document.querySelector("#logo-url");
  logoUrl.value = "";
  const logoSearchButton = document.querySelector("#logo-search-button");
  logoSearchButton.classList.add("disabled");
  const submitButton = document.querySelector("#save-button");
  submitButton.disabled = false;
  const autoRenew = document.querySelector("#auto_renew");
  autoRenew.checked = true;
  const startDate = document.querySelector("#start_date");
  startDate.value = new Date().toISOString().split('T')[0];
  const notifyDaysBefore = document.querySelector("#notify_days_before");
  notifyDaysBefore.disabled = true;
  const replacementSubscriptionIdSelect = document.querySelector("#replacement_subscription_id");
  replacementSubscriptionIdSelect.value = "0";
  const replacementSubscription = document.querySelector(`#replacement_subscritpion`);
  replacementSubscription.classList.add("hide");
  const form = document.querySelector("#subs-form");
  form.reset();
  delete form.dataset.anchorDay;
  toggleOneTimeCycleUI(false);
  toggleEndsFields();
  toggleVariableAmount();
  toggleRenewFromAction();
  toggleCancelNoticeFields(false);
  delete document.querySelector("#next_payment").dataset.trialBase;
  toggleIntroFields();
  updatePriceStep();
  setActiveMonths(4095);
  setSharingFields(null);
  setSharedEditMode(null);
  rememberPriceForChange(null);
  closeLogoSearch();
  const deleteButton = document.querySelector("#deletesub");
  deleteButton.style = 'display: none';
  deleteButton.removeAttribute("onClick");
}

// Picks the logo filename that reads well on the theme currently in use,
// falling back to the original when there's no themed variant for it.
function logoFilenameForCurrentTheme(subscription) {
  if (!subscription.logo_text_color || !subscription.logo_variant) {
    return subscription.logo;
  }
  const nativeTheme = subscription.logo_text_color === 'dark' ? 'light' : 'dark';
  const currentTheme = document.body.classList.contains('dark') ? 'dark' : 'light';
  return currentTheme === nativeTheme ? subscription.logo : subscription.logo_variant;
}

function fillEditFormFields(subscription) {
  const formTitle = document.querySelector("#form-title");
  formTitle.textContent = translate('edit_subscription');
  const logo = document.querySelector("#form-logo");
  const logoFile = subscription.logo ? "images/uploads/logos/" + logoFilenameForCurrentTheme(subscription) : "";
  if (logoFile) {
    logo.src = logoFile;
    logo.style = 'display: block';
  }
  const logoSearchButton = document.querySelector("#logo-search-button");
  logoSearchButton.classList.remove("disabled");
  const id = document.querySelector("#id");
  id.value = subscription.id;
  const name = document.querySelector("#name");
  name.value = subscription.name;
  const price = document.querySelector("#price");
  price.value = subscription.price;
  document.querySelector("#tier_name").value = subscription.tier_name || '';

  const currencySelect = document.querySelector("#currency");
  currencySelect.value = subscription.currency_id.toString();
  updatePriceStep();
  const frequencySelect = document.querySelector("#frequency");
  frequencySelect.value = subscription.frequency;
  const cycleSelect = document.querySelector("#cycle");
  cycleSelect.value = subscription.cycle;
  toggleOneTimeCycleUI(subscription.cycle == 5);
  setActiveMonths(subscription.active_months);
  setSharingFields(subscription);
  const paymentSelect = document.querySelector("#payment_method");
  paymentSelect.value = subscription.payment_method_id;
  const categorySelect = document.querySelector("#category");
  categorySelect.value = subscription.category_id;
  const payerSelect = document.querySelector("#payer_user");
  payerSelect.value = subscription.payer_user_id;

  const startDate = document.querySelector("#start_date");
  startDate.value = subscription.start_date;
  const nextPament = document.querySelector("#next_payment");
  nextPament.value = subscription.next_payment;
  document.querySelector("#cancellation_date").value = subscription.cancellation_date || '';
  document.querySelector("#cancel_notice_mode").value = subscription.cancel_notice_mode || 'none';
  document.querySelector("#cancel_notice_value").value = subscription.cancel_notice_value ?? '';
  document.querySelector("#cancel_notice_unit").value = subscription.cancel_notice_unit || 'months';
  document.querySelector("#min_term_end").value = subscription.min_term_end || '';

  const notes = document.querySelector("#notes");
  notes.value = subscription.notes;
  const inactive = document.querySelector("#inactive");
  inactive.checked = subscription.inactive;
  const url = document.querySelector("#url");
  url.value = subscription.url;

  const autoRenew = document.querySelector("#auto_renew");
  if (autoRenew) {
    autoRenew.checked = subscription.auto_renew;
  }

  const notifications = document.querySelector("#notifications");
  if (notifications) {
    notifications.checked = subscription.notify;
  }

  document.querySelector("#variable_amount").checked = Number(subscription.variable_amount) === 1;
  document.querySelector("#renew_from_action").checked = Number(subscription.renew_from_action) === 1;
  document.querySelector("#ends_mode").value = subscription.ends_mode || 'never';
  document.querySelector("#end_date").value = subscription.end_date || '';
  document.querySelector("#total_payments").value = subscription.total_payments || '';
  document.querySelector("#subs-form").dataset.anchorDay = subscription.billing_anchor_day || '';
  toggleEndsFields();
  toggleVariableAmount();
  toggleRenewFromAction();
  toggleCancelNoticeFields(false);
  document.querySelector("#intro_type").value = subscription.intro_type || 'none';
  document.querySelector("#intro_price").value = subscription.intro_price ?? '';
  document.querySelector("#intro_length_mode").value = subscription.intro_type ? 'date' : 'cycles';
  document.querySelector("#intro_cycles").value = '';
  document.querySelector("#intro_end_date").value = subscription.intro_end_date || '';
  document.querySelector("#intro_notify_days").value = subscription.intro_notify_days || 3;
  delete document.querySelector("#next_payment").dataset.trialBase;
  toggleIntroFields();
  const visibility = document.querySelector("#visibility");
  if (visibility) {
    visibility.value = subscription.shared ? 'household' : 'private';
  }
  const notifyDaysBefore = document.querySelector("#notify_days_before");
  notifyDaysBefore.value = subscription.notify_days_before ?? 0;
  if (subscription.notify === 1) {
    notifyDaysBefore.disabled = false;
  }

  const replacementSubscriptionIdSelect = document.querySelector("#replacement_subscription_id");
  replacementSubscriptionIdSelect.value = subscription.replacement_subscription_id ?? 0;

  const replacementSubscription = document.querySelector(`#replacement_subscritpion`);
  if (subscription.inactive) {
    replacementSubscription.classList.remove("hide");
  } else {
    replacementSubscription.classList.add("hide");
  }

  const deleteButton = document.querySelector("#deletesub");
  deleteButton.style = 'display: block';
  deleteButton.setAttribute("onClick", `deleteSubscription(event, ${subscription.id})`);

  // Last, so nothing above re-enables a locked field or shows Delete again.
  setSharedEditMode(subscription);
  rememberPriceForChange(subscription);

  setFormOpen(true);
}

function openEditSubscription(event, id) {
  if (event) {
    event.stopPropagation();
  }
  scrollTopBeforeOpening = window.scrollY;
  const url = `endpoints/subscription/get.php?id=${id}`;
  fetch(url)
    .then((response) => {
      if (response.ok) {
        return response.json();
      } else {
        showErrorMessage(translate('failed_to_load_subscription'));
      }
    })
    .then((data) => {
      if (data.error || data === "Error") {
        showErrorMessage(translate('failed_to_load_subscription'));
      } else {
        const subscription = data;
        fillEditFormFields(subscription);
      }
    })
    .catch((error) => {
      console.log(error);
      showErrorMessage(translate('failed_to_load_subscription'));
    });
}

function addSubscription() {
  resetForm();
  const startDate = document.querySelector("#start_date");
  startDate.value = new Date().toISOString().split('T')[0];
  setFormOpen(true);
}

function closeAddSubscription() {
  const wasOpen = document.getElementById('subscription-form').classList.contains('is-open');
  setFormOpen(false);
  if (shouldScroll && wasOpen) {
    window.scrollTo(0, scrollTopBeforeOpening);
  }
  resetForm();
}

function handleFileSelect(event) {
  const fileInput = event.target;
  const logoPreview = document.querySelector('.logo-preview');
  const logoImg = logoPreview.querySelector('img');
  const logoUrl = document.querySelector("#logo-url");
  logoUrl.value = "";

  if (fileInput.files && fileInput.files[0]) {
    const reader = new FileReader();

    reader.onload = function (e) {
      logoImg.src = e.target.result;
      logoImg.style.display = 'block';
    };

    reader.readAsDataURL(fileInput.files[0]);
  }
}

function setSearchButtonStatus() {

  const nameInput = document.querySelector("#name");
  const hasSearchTerm = nameInput.value.trim().length > 0;
  const logoSearchButton = document.querySelector("#logo-search-button");
  if (hasSearchTerm) {
    logoSearchButton.classList.remove("disabled");
  } else {
    logoSearchButton.classList.add("disabled");
  }

}

function searchLogo() {
  const nameInput = document.querySelector("#name");
  const searchTerm = nameInput.value.trim();
  if (searchTerm === "") {
    nameInput.focus();
    return;
  }

  const logoSearchPopup = document.querySelector("#logo-search-results");
  const logoResults = document.querySelector("#logo-search-images");
  const logoNav = document.querySelector("#logo-search-nav");
  const logoSearchBackdrop = document.querySelector("#logo-search-backdrop");
  logoSearchPopup.classList.add("is-open");
  if (logoSearchBackdrop) {
    logoSearchBackdrop.classList.add("is-open");
  }
  const subscriptionForm = document.querySelector("#subscription-form");
  if (subscriptionForm) {
    subscriptionForm.classList.add("scroll-locked");
  }

  const queryInput = document.querySelector("#logo-search-query");
  queryInput.value = searchTerm;

  runLogoSearch(searchTerm);
}

function submitLogoSearch() {
  const queryInput = document.querySelector("#logo-search-query");
  const searchTerm = queryInput.value.trim();
  if (searchTerm === "") {
    queryInput.focus();
    return;
  }

  runLogoSearch(searchTerm);
}

const logoSearchQueryInput = document.querySelector("#logo-search-query");
const logoSearchSubmitButton = document.querySelector("#logo-search-submit");

if (logoSearchQueryInput && logoSearchSubmitButton) {
  logoSearchSubmitButton.addEventListener("click", submitLogoSearch);
  logoSearchQueryInput.addEventListener("keydown", function (event) {
    if (event.key === "Enter") {
      event.preventDefault();
      submitLogoSearch();
    }
  });
}

function runLogoSearch(searchTerm) {
  const logoSearchPopup = document.querySelector("#logo-search-results");
  const logoResults = document.querySelector("#logo-search-images");
  const logoNav = document.querySelector("#logo-search-nav");
  const logoSearchTitle = document.querySelector("#logo-search-title");
  if (logoSearchTitle) {
    const baseTitle = logoSearchTitle.dataset.title;
    logoSearchTitle.textContent = `${baseTitle}: ${searchTerm}`;
  }

  // One section per source, queried in parallel and filled as each response lands
  const encodedSearchTerm = encodeURIComponent(searchTerm);
  const sources = [
    { label: 'selfh.st', url: `endpoints/logos/icon_search.php?search=${encodedSearchTerm}&source=selfhst` },
    { label: 'Dashboard Icons', url: `endpoints/logos/icon_search.php?search=${encodedSearchTerm}&source=dashboardicons` },
    { label: 'DuckDuckGo', url: `endpoints/logos/search.php?search=${encodedSearchTerm}&source=duckduckgo` },
    { label: 'Brave', url: `endpoints/logos/search.php?search=${encodedSearchTerm}&source=brave` },
  ];

  // Google requires user-provided API credentials; the section only exists when configured
  if (logoSearchPopup.dataset.googleSearch) {
    sources.unshift({ label: 'Google', url: `endpoints/logos/google_search.php?search=${encodedSearchTerm}` });
  }

  logoResults.innerHTML = "";
  if (logoNav) {
    logoNav.innerHTML = "";
  }

  sources.forEach(source => {
    const section = document.createElement("div");
    section.className = "logo-search-section";

    const title = document.createElement("h4");
    title.textContent = source.label;
    section.appendChild(title);

    const resultsContainer = document.createElement("div");
    resultsContainer.className = "logo-search-section-results";
    section.appendChild(resultsContainer);

    logoResults.appendChild(section);
    showSearchState(resultsContainer, 'loading');

    if (logoNav) {
      const navItem = document.createElement("button");
      navItem.type = "button";
      navItem.className = "logo-search-nav-item";
      navItem.textContent = source.label;
      navItem.onclick = function () {
        const targetTop = section.getBoundingClientRect().top - logoResults.getBoundingClientRect().top + logoResults.scrollTop;
        const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        logoResults.scrollTo({ top: targetTop, behavior: reduceMotion ? 'auto' : 'smooth' });
      };
      logoNav.appendChild(navItem);
    }

    fetchLogoSearchSource(source.url)
      .then(data => {
        if (data.results && data.results.length > 0) {
          displayImageResults(data.results, resultsContainer);
        } else if (data.error) {
          console.error(source.label, data.error);
          showSearchState(resultsContainer, 'error');
        } else {
          showSearchState(resultsContainer, 'empty');
        }
      })
      .catch(error => {
        console.error(translate('error_fetching_image_results'), source.label, error);
        showSearchState(resultsContainer, 'error');
      });
  });
}

function fetchLogoSearchSource(url, retry = true) {
  return fetch(url, {
    cache: "no-store",
    headers: { "Accept": "application/json" },
  })
    .then(response => {
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
      }
      return response.text();
    })
    .then(body => {
      try {
        return JSON.parse(body);
      } catch (error) {
        throw new Error("Invalid JSON response");
      }
    })
    .catch(error => {
      if (retry) {
        // Wait a moment before retrying: sources like Brave rate-limit
        // aggressively, and retrying instantly just repeats the failure.
        return new Promise(resolve => setTimeout(resolve, 600))
          .then(() => fetchLogoSearchSource(url, false));
      }
      throw error;
    });
}

function displayImageResults(imageSources, container) {
  container.innerHTML = "";

  imageSources.forEach(src => {
    const img = document.createElement("img");
    img.src = src.thumbnail || src.image;
    img.onclick = function () {
      const selectedUrl = getSupportedLogoUrl(src);
      if (selectedUrl) {
        selectWebLogo(selectedUrl, img.src);
      }
    };
    img.onerror = function () {
      this.parentNode.removeChild(this);
    };
    container.appendChild(img);
  });
}

function getSupportedLogoUrl(source) {
  if (source.image) {
    try {
      const imagePath = new URL(source.image, window.location.href).pathname.toLowerCase();
      if (/\.(png|jpe?g|gif|webp)$/.test(imagePath)) {
        return source.image;
      }
    } catch (error) {
      // Fall through to the raster thumbnail for malformed source URLs.
    }
  }

  return source.thumbnail || "";
}

function selectWebLogo(url, previewUrl = url) {
  closeLogoSearch();
  const logoPreview = document.querySelector("#form-logo");
  const logoUrl = document.querySelector("#logo-url");
  logoPreview.src = previewUrl;
  logoPreview.style.display = 'block';
  logoUrl.value = url;
}

function closeLogoSearch() {
  const logoSearchPopup = document.querySelector("#logo-search-results");
  logoSearchPopup.classList.remove("is-open");
  const logoSearchBackdrop = document.querySelector("#logo-search-backdrop");
  if (logoSearchBackdrop) {
    logoSearchBackdrop.classList.remove("is-open");
  }
  const subscriptionForm = document.querySelector("#subscription-form");
  if (subscriptionForm) {
    subscriptionForm.classList.remove("scroll-locked");
  }
  const logoSearchTitle = document.querySelector("#logo-search-title");
  if (logoSearchTitle) {
    logoSearchTitle.textContent = logoSearchTitle.dataset.title;
  }
  const logoResults = document.querySelector("#logo-search-images");
  logoResults.innerHTML = "";
}

function fetchSubscriptions(id, event, initiator) {
  const subscriptionsContainer = document.querySelector("#subscriptions");
  let getSubscriptions = "endpoints/subscriptions/get.php";

  if (activeFilters['categories'].length > 0) {
    getSubscriptions += `?categories=${activeFilters['categories']}`;
  }
  if (activeFilters['members'].length > 0) {
    getSubscriptions += getSubscriptions.includes("?") ? `&members=${activeFilters['members']}` : `?members=${activeFilters['members']}`;
  }
  if (activeFilters['payments'].length > 0) {
    getSubscriptions += getSubscriptions.includes("?") ? `&payments=${activeFilters['payments']}` : `?payments=${activeFilters['payments']}`;
  }
  if (activeFilters['state'] !== "") {
    getSubscriptions += getSubscriptions.includes("?") ? `&state=${activeFilters['state']}` : `?state=${activeFilters['state']}`;
  }
  if (activeFilters['renewalType'] !== "") {
    getSubscriptions += getSubscriptions.includes("?") ? `&renewalType=${activeFilters['renewalType']}` : `?renewalType=${activeFilters['renewalType']}`;
  }
  if (activeFilters['notifications'].length > 0) {
    getSubscriptions += getSubscriptions.includes("?") ? `&notifications=${activeFilters['notifications']}` : `?notifications=${activeFilters['notifications']}`;
  }

  fetch(getSubscriptions)
    .then(response => response.text())
    .then(data => {
      if (data) {
        subscriptionsContainer.innerHTML = data;
      }

      if (initiator == "clone" && id && event) {
        openEditSubscription(event, id);
      }

      // The first subscription added replaces the empty page: reload so the
      // toolbar (search, filter, sort) appears too.
      if (initiator === "add" && document.querySelector('.subs-layout.is-empty')) {
        window.location.reload();
        return;
      }

      searchSubscriptions();
      syncDetailsWithList(initiator === "renew" ? id : null);
    })
    .catch(error => {
      console.error(translate('error_reloading_subscription'), error);
    });
}

function storeSortOption(sortOption) {
  const daysToExpire = 30;
  const expirationDate = new Date();
  expirationDate.setDate(expirationDate.getDate() + daysToExpire);
  const cookieValue = encodeURIComponent(sortOption) + '; expires=' + expirationDate.toUTCString();
  document.cookie = 'sortOrder=' + cookieValue + '; SameSite=Lax';
}

function setSortOption(sortOption) {
  storeSortOption(sortOption);
  fetchSubscriptions(null, null, "sort");
}

function convertSvgToPng(file, callback) {
  const reader = new FileReader();

  reader.onload = function (e) {
    const img = new Image();
    img.src = e.target.result;
    img.onload = function () {
      const canvas = document.createElement('canvas');
      canvas.width = img.width;
      canvas.height = img.height;
      const ctx = canvas.getContext('2d');
      ctx.drawImage(img, 0, 0);
      const pngDataUrl = canvas.toDataURL('image/png');
      const pngFile = dataURLtoFile(pngDataUrl, file.name.replace(".svg", ".png"));
      callback(pngFile);
    };
  };

  reader.readAsDataURL(file);
}

function dataURLtoFile(dataurl, filename) {
  let arr = dataurl.split(','),
    mime = arr[0].match(/:(.*?);/)[1],
    bstr = atob(arr[1]),
    n = bstr.length,
    u8arr = new Uint8Array(n);

  while (n--) {
    u8arr[n] = bstr.charCodeAt(n);
  }

  return new File([u8arr], filename, { type: mime });
}

function submitFormData(formData, submitButton, endpoint) {
  fetch(endpoint, {
    method: "POST",
    headers: {
      "X-CSRF-Token": window.csrfToken,
    },
    body: formData,
  })
    .then((response) => response.json())
    .then((data) => {
      if (data.status === "Success") {
        showSuccessMessage(data.message);
        if (data.logo_warning) {
          showErrorMessage(data.logo_warning);
        }
        fetchSubscriptions(null, null, "add");
        closeAddSubscription();
      } else {
        showErrorMessage(data.message || translate("unknown_error"));
      }
    })
    .catch((error) => {
      console.error(error);
      showErrorMessage(translate("unknown_error"));
    })
    .finally(() => {
      submitButton.disabled = false;
    });
}

document.addEventListener('DOMContentLoaded', function () {
  const cycleSelectEl = document.querySelector("#cycle");
  if (cycleSelectEl) {
    cycleSelectEl.addEventListener("change", function () {
      toggleOneTimeCycleUI(this.value === "5");
    });
  }
});

document.addEventListener('DOMContentLoaded', function () {
  const subscriptionForm = document.querySelector("#subs-form");
  const submitButton = document.querySelector("#save-button");
  const endpoint = "endpoints/subscription/add.php";

  subscriptionForm.addEventListener("submit", function (e) {
    e.preventDefault();

    submitButton.disabled = true;

    const cycleVal = document.querySelector("#cycle")?.value;
    if (cycleVal === "5") {
      const freq = document.querySelector("#frequency");
      if (freq) freq.value = 1;
      const cancellationDate = document.querySelector("#cancellation_date");
      if (cancellationDate) cancellationDate.value = "";
      const cancelNoticeMode = document.querySelector("#cancel_notice_mode");
      if (cancelNoticeMode) cancelNoticeMode.value = "none";
      const introType = document.querySelector("#intro_type");
      if (introType) introType.value = "none";
      const notifyDays = document.querySelector("#notify_days_before");
      if (notifyDays) notifyDays.value = -1;
    }

    const formData = new FormData(subscriptionForm);

    const fileInput = document.querySelector("#logo");
    const file = fileInput.files[0];

    if (file && file.type === "image/svg+xml") {
      convertSvgToPng(file, function (pngFile) {
        formData.set("logo", pngFile);
        submitFormData(formData, submitButton, endpoint);
      });
    } else {
      submitFormData(formData, submitButton, endpoint);
    }
  });
});

function searchSubscriptions() {
  const searchInput = document.querySelector("#search");
  if (!searchInput) {
    return;
  }
  const searchTerm = searchInput.value.trim().toLowerCase();
  searchInput.closest('.search-field').classList.toggle("has-text", searchTerm.length > 0);

  document.querySelectorAll("#subscriptions .group").forEach((group) => {
    let visible = 0;
    group.querySelectorAll(".list-row").forEach((row) => {
      const matches = row.getAttribute('data-name').toLowerCase().includes(searchTerm);
      row.classList.toggle("hide", !matches);
      row.classList.toggle("is-first", matches && visible === 0);
      if (matches) {
        visible++;
      }
    });
    group.classList.toggle("hide", visible === 0);
  });

  const noResults = document.querySelector("#search-no-results");
  const anyVisible = document.querySelector("#subscriptions .group:not(.hide)");
  const hasGroups = document.querySelector("#subscriptions .group");
  if (noResults) {
    noResults.classList.toggle("hide", !hasGroups || !!anyVisible);
  }
}

function clearSearch() {
  const searchInput = document.querySelector("#search");
  searchInput.value = "";
  searchSubscriptions();
}

// Keep the details panel in step with the list after it is re-rendered: show
// the same subscription if it is still there, otherwise (wide screens, where
// the panel is a permanent column) the first one.
function syncDetailsWithList(reloadId) {
  if (typeof currentDetailsId === 'undefined') {
    return;
  }
  const keepId = reloadId || currentDetailsId;
  if (keepId && document.querySelector(`.list-row[data-id="${keepId}"]`)) {
    loadSubscriptionDetails(keepId, false);
    return;
  }
  if (keepId) {
    closeSubscriptionDetails();
  }
  selectFirstSubscriptionWhenInline();
}

function selectFirstSubscriptionWhenInline() {
  if (!detailsIsInline() || currentDetailsId) {
    return;
  }
  const firstRow = document.querySelector('#subscriptions .list-row:not(.hide)');
  if (firstRow) {
    loadSubscriptionDetails(firstRow.dataset.id, false);
  }
}

function closeSubMenus() {
  var subMenus = document.querySelectorAll('.filtermenu-submenu-content');
  subMenus.forEach(subMenu => {
    subMenu.classList.remove('is-open');
  });

}

const activeFilters = [];
activeFilters['categories'] = [];
activeFilters['members'] = [];
activeFilters['payments'] = [];
activeFilters['state'] = "";
activeFilters['renewalType'] = "";
activeFilters['notifications'] = [];

document.addEventListener("DOMContentLoaded", function () {
  var filtermenu = document.querySelector('#filtermenu-button');
  if (!filtermenu) {
    return;
  }
  filtermenu.addEventListener('click', function () {
    this.parentElement.querySelector('.filtermenu-content').classList.toggle('is-open');
    closeSubMenus();
  });

  document.addEventListener('click', function (e) {
    var filtermenuContent = document.querySelector('.filtermenu-content');
    if (filtermenuContent && filtermenuContent.classList.contains('is-open')) {
      var subMenus = document.querySelectorAll('.filtermenu-submenu');
      var clickedInsideSubmenu = Array.from(subMenus).some(subMenu => subMenu.contains(e.target) || subMenu === e.target);

      if (!filtermenu.contains(e.target) && !clickedInsideSubmenu) {
        closeSubMenus();
        filtermenuContent.classList.remove('is-open');
      }
    }
  });

  initFiltersFromUrl();
  searchSubscriptions();
  selectFirstSubscriptionWhenInline();

  // "/" jumps to search, as in the toolbar hint.
  document.addEventListener('keydown', function (event) {
    const tag = (document.activeElement || {}).tagName;
    const search = document.querySelector('#search');
    if (event.key === '/' && search && !['INPUT', 'SELECT', 'TEXTAREA'].includes(tag) && !event.metaKey && !event.ctrlKey) {
      event.preventDefault();
      search.focus();
    }
    if (event.key === 'Escape' && document.querySelector('#subscription-form.is-open')) {
      closeAddSubscription();
    }
  });

  // Moving between layouts (e.g. resizing past the wide breakpoint) can turn
  // the panel into the inline column; make sure it has something to show.
  const wideQuery = window.matchMedia('(min-width: 1100px)');
  wideQuery.addEventListener('change', selectFirstSubscriptionWhenInline);
});

// Filters can arrive in the URL, e.g. a category picked in the sidebar.
function initFiltersFromUrl() {
  const params = new URLSearchParams(window.location.search);
  const map = { category: 'categories', member: 'members', payment: 'payments' };
  Object.keys(map).forEach((param) => {
    if (params.get(param)) {
      activeFilters[map[param]] = params.get(param).split(',').filter(Boolean);
    }
  });
  if (params.get('state') !== null && params.get('state') !== '') {
    activeFilters['state'] = params.get('state');
  }
  if (params.get('notifications')) {
    activeFilters['notifications'] = params.get('notifications').split(',')
      .filter((type) => ['reminder', 'cancellation', 'none'].includes(type));
  }
  // A sort picked by the link (the server already applied it) is kept for
  // later visits and list refreshes, as if chosen from the menu.
  const sortSelect = document.querySelector('#sort-select');
  if (params.get('sort') && sortSelect && sortSelect.value === params.get('sort')) {
    storeSortOption(params.get('sort'));
  }
  const clear = document.querySelector('#clear-filters');
  if (clear && (activeFilters['categories'].length || activeFilters['members'].length || activeFilters['payments'].length
    || activeFilters['state'] !== '' || activeFilters['notifications'].length)) {
    clear.classList.remove('hide');
  }
}

function toggleSubMenu(subMenu) {
  var subMenu = document.getElementById("filter-" + subMenu);
  if (subMenu.classList.contains("is-open")) {
    closeSubMenus();
  } else {
    closeSubMenus();
    subMenu.classList.add("is-open");
  }
}

function toggleReplacementSub() {
  const checkbox = document.getElementById('inactive');
  const replacementSubscription = document.querySelector(`#replacement_subscritpion`);

  if (checkbox.checked) {
    replacementSubscription.classList.remove("hide");
  } else {
    replacementSubscription.classList.add("hide");
  }
}

document.querySelectorAll('.filter-item').forEach(function (item) {
  item.addEventListener('click', function (e) {
    const searchInput = document.querySelector("#search");
    searchInput.value = "";

    if (this.hasAttribute('data-categoryid')) {
      const categoryId = this.getAttribute('data-categoryid');
      if (activeFilters['categories'].includes(categoryId)) {
        const categoryIndex = activeFilters['categories'].indexOf(categoryId);
        activeFilters['categories'].splice(categoryIndex, 1);
        this.classList.remove('selected');
      } else {
        activeFilters['categories'].push(categoryId);
        this.classList.add('selected');
      }
    } else if (this.hasAttribute('data-memberid')) {
      const memberId = this.getAttribute('data-memberid');
      if (activeFilters['members'].includes(memberId)) {
        const memberIndex = activeFilters['members'].indexOf(memberId);
        activeFilters['members'].splice(memberIndex, 1);
        this.classList.remove('selected');
      } else {
        activeFilters['members'].push(memberId);
        this.classList.add('selected');
      }
    } else if (this.hasAttribute('data-paymentid')) {
      const paymentId = this.getAttribute('data-paymentid');
      if (activeFilters['payments'].includes(paymentId)) {
        const paymentIndex = activeFilters['payments'].indexOf(paymentId);
        activeFilters['payments'].splice(paymentIndex, 1);
        this.classList.remove('selected');
      } else {
        activeFilters['payments'].push(paymentId);
        this.classList.add('selected');
      }
    } else if (this.hasAttribute('data-state')) {
      const state = this.getAttribute('data-state');
      if (activeFilters['state'] === state) {
        activeFilters['state'] = "";
        this.classList.remove('selected');
      } else {
        activeFilters['state'] = state;
        Array.from(this.parentNode.children).forEach(sibling => {
          sibling.classList.remove('selected');
        });
        this.classList.add('selected');
      }
    } else if (this.hasAttribute('data-renewaltype')) {
      const renewalType = this.getAttribute('data-renewaltype');
      if (activeFilters['renewalType'] === renewalType) {
        activeFilters['renewalType'] = "";
        this.classList.remove('selected');
      } else {
        activeFilters['renewalType'] = renewalType;
        Array.from(this.parentNode.children).forEach(sibling => {
          sibling.classList.remove('selected');
        });
        this.classList.add('selected');
      }
    } else if (this.hasAttribute('data-notificationtype')) {
      const notifType = this.getAttribute('data-notificationtype');
      if (activeFilters['notifications'].includes(notifType)) {
        const idx = activeFilters['notifications'].indexOf(notifType);
        activeFilters['notifications'].splice(idx, 1);
        this.classList.remove('selected');
      } else {
        activeFilters['notifications'].push(notifType);
        this.classList.add('selected');
      }
    }

    if (activeFilters['categories'].length > 0 || activeFilters['members'].length > 0 ||
       activeFilters['payments'].length > 0 || activeFilters['state'] !== "" ||
       activeFilters['renewalType'] !== "" || activeFilters['notifications'].length > 0) {
      document.querySelector('#clear-filters').classList.remove('hide');
    } else {
      document.querySelector('#clear-filters').classList.add('hide');
    }

    fetchSubscriptions(null, null, "filter");
  });
});

function clearFilters() {
  const searchInput = document.querySelector("#search");
  searchInput.value = "";
  activeFilters['categories'] = [];
  activeFilters['members'] = [];
  activeFilters['payments'] = [];
  activeFilters['state'] = "";
  activeFilters['renewalType'] = "";
  activeFilters['notifications'] = [];

  document.querySelectorAll('.filter-item').forEach(function (item) {
    item.classList.remove('selected');
  });
  document.querySelector('#clear-filters').classList.add('hide');
  fetchSubscriptions(null, null, "clearfilters");
}

function toggleOneTimeCycleUI(isOneTime) {
  const frequencySelect = document.querySelector("#frequency");
  const autoRenewGroup = document.querySelector("#auto-renew-group");
  const autoRenewCheckbox = document.querySelector("#auto_renew");
  const autofillDesktop = document.querySelector("#autofill-next-payment-button.hideOnMobile");
  const labelRecurring = document.querySelector("#next-payment-label-recurring");
  const labelOnetime = document.querySelector("#next-payment-label-onetime");
  const notificationsGroup = document.querySelector("#notifications-group");
  const notifyDaysCancellationGroup = document.querySelector("#notify-days-cancellation-group");
  const cancelNoticeGroup = document.querySelector("#cancel-notice-group");
  if (cancelNoticeGroup) cancelNoticeGroup.classList.toggle('hide', isOneTime);
  const introGroup = document.querySelector("#intro-group");
  if (introGroup) introGroup.classList.toggle('hide', isOneTime);
  ['#intro_type', '#intro_price', '#intro_length_mode', '#intro_cycles', '#intro_end_date', '#intro_notify_days'].forEach((selector) => {
    const field = document.querySelector(selector);
    if (field) field.disabled = isOneTime;
  });
  ['#cancel_notice_mode', '#cancellation_date', '#cancel_notice_value', '#cancel_notice_unit', '#min_term_end'].forEach((selector) => {
    const field = document.querySelector(selector);
    if (field) field.disabled = isOneTime;
  });
  const notificationsCheckbox = document.querySelector("#notifications");
  const endsGroup = document.querySelector("#ends-group");
  const renewFromActionGroup = document.querySelector("#renew-from-action-group");
  if (endsGroup) endsGroup.classList.toggle('hide', isOneTime);
  // Hidden fields still take part in validation: disable them for a one-time
  // payment so a required end date can't block saving, and restore them after.
  ['#ends_mode', '#end_date', '#total_payments'].forEach((selector) => {
    const field = document.querySelector(selector);
    if (field) field.disabled = isOneTime;
  });
  if (!isOneTime && document.querySelector("#ends_mode")) toggleEndsFields();
  if (renewFromActionGroup && isOneTime) renewFromActionGroup.classList.add('hide');

  if (isOneTime) {
    if (frequencySelect) frequencySelect.style.display = 'none';
    if (autoRenewGroup) autoRenewGroup.style.display = 'none';
    if (autoRenewCheckbox) { autoRenewCheckbox.checked = false; autoRenewCheckbox.disabled = true; }
    if (autofillDesktop) autofillDesktop.style.display = 'none';
    if (labelRecurring) labelRecurring.style.display = 'none';
    if (labelOnetime) labelOnetime.style.display = '';
    if (notificationsGroup) notificationsGroup.style.display = 'none';
    if (notifyDaysCancellationGroup) notifyDaysCancellationGroup.style.display = 'none';
    if (notificationsCheckbox) { notificationsCheckbox.checked = false; notificationsCheckbox.disabled = true; }
  } else {
    if (frequencySelect) frequencySelect.style.display = '';
    if (autoRenewGroup) autoRenewGroup.style.display = '';
    if (autoRenewCheckbox) autoRenewCheckbox.disabled = false;
    if (autofillDesktop) autofillDesktop.style.display = '';
    if (labelRecurring) labelRecurring.style.display = '';
    if (labelOnetime) labelOnetime.style.display = 'none';
    if (notificationsGroup) notificationsGroup.style.display = '';
    if (notifyDaysCancellationGroup) notifyDaysCancellationGroup.style.display = '';
    if (notificationsCheckbox) notificationsCheckbox.disabled = false;
    toggleRenewFromAction();
  }
}

function autoFillNextPaymentDate(e) {
  e.preventDefault();
  const frequencySelect = document.querySelector("#frequency");
  const cycleSelect = document.querySelector("#cycle"); 
  const startDate = document.querySelector("#start_date");
  const nextPayment = document.querySelector("#next_payment"); 

  // Do nothing if frequency, cycle, or start date is not set
  if (!frequencySelect.value || !cycleSelect.value || !startDate.value || isNaN(Date.parse(startDate.value))) {
    console.log(frequencySelect.value, cycleSelect.value, startDate.value);
    return;
  }
  
  const today = new Date();  
  const cycle = cycleSelect.value;
  const frequency = Number(frequencySelect.value);

  const baseDate = new Date(startDate.value);
  let nextDate = new Date(baseDate);
  let safetyCounter = 0;
  const maxIterations = 1000;

  // Each occurrence is computed from the start date, with months and years
  // clamped to the end of the month, so Jan 31 renews Feb 28 then Mar 31.
  // Dates in an inactive month (Settings: Active months) are skipped too.
  while ((nextDate <= today || !formMonthIsActive(nextDate)) && safetyCounter < maxIterations) {
    safetyCounter++;
    switch (cycle) {
    case '1': // Days
      nextDate = new Date(baseDate);
      nextDate.setDate(baseDate.getDate() + frequency * safetyCounter);
      break;
    case '2': // Weeks
      nextDate = new Date(baseDate);
      nextDate.setDate(baseDate.getDate() + 7 * frequency * safetyCounter);
      break;
    case '3': // Months
      nextDate = addMonthsClamped(baseDate, frequency * safetyCounter);
      break;
    case '4': // Years
      nextDate = addMonthsClamped(baseDate, 12 * frequency * safetyCounter);
      break;
    default:
    }
  }

if (safetyCounter === maxIterations) {
  return;
}

nextPayment.value = toISOStringWithTimezone(nextDate).substring(0, 10);
}

function addMonthsClamped(date, months) {
  const result = new Date(date);
  result.setDate(1);
  result.setMonth(result.getMonth() + months);
  const lastDay = new Date(result.getFullYear(), result.getMonth() + 1, 0).getDate();
  result.setDate(Math.min(date.getDate(), lastDay));
  return result;
}

function toISOStringWithTimezone(date) {
  const pad = n => String(Math.floor(Math.abs(n))).padStart(2, '0');
  const tzOffset = -date.getTimezoneOffset();
  const sign = tzOffset >= 0 ? '+' : '-';
  const hoursOffset = pad(tzOffset / 60);
  const minutesOffset = pad(tzOffset % 60);

  return date.getFullYear() +
    '-' + pad(date.getMonth() + 1) +
    '-' + pad(date.getDate()) +
    'T' + pad(date.getHours()) +
    ':' + pad(date.getMinutes()) +
    ':' + pad(date.getSeconds()) +
    sign + hoursOffset +
    ':' + minutesOffset;
}

function insertMarkdownSyntax(textarea, prefix, suffix, placeholder) {
  const start = textarea.selectionStart;
  const end = textarea.selectionEnd;
  const selected = textarea.value.slice(start, end) || placeholder;

  textarea.setRangeText(prefix + selected + suffix, start, end, 'select');
  textarea.focus();
  textarea.setSelectionRange(start + prefix.length, start + prefix.length + selected.length);
}

function applyNotesMarkdown(action) {
  const textarea = document.querySelector('#notes');
  if (!textarea) {
    return;
  }

  switch (action) {
    case 'bold':
      insertMarkdownSyntax(textarea, '**', '**', translate('notes_bold'));
      break;
    case 'italic':
      insertMarkdownSyntax(textarea, '_', '_', translate('notes_italic'));
      break;
    case 'list': {
      const start = textarea.selectionStart;
      const lineStart = textarea.value.lastIndexOf('\n', start - 1) + 1;
      textarea.setRangeText('- ', lineStart, lineStart, 'end');
      textarea.focus();
      break;
    }
    case 'link':
      insertMarkdownSyntax(textarea, '[', '](https://)', translate('notes_link'));
      break;
  }
}


/*
  Schedule helpers for the form. They mirror includes/recurrence.php: month
  and year steps clamp to the end of the month and return to the anchor day,
  so a bill on the 31st goes Jan 31, Feb 28, Mar 31. The shared vectors live
  in tests/cases/recurrence_test.php.
*/
function parseFormDate(value) {
  if (!value || !/^\d{4}-\d{2}-\d{2}$/.test(value)) {
    return null;
  }
  const [year, month, day] = value.split('-').map(Number);
  return new Date(year, month - 1, day);
}

function formatFormDate(date) {
  const pad = (n) => String(n).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

function stepOccurrence(date, cycle, frequency, steps, anchorDay) {
  const result = new Date(date);
  switch (String(cycle)) {
    case '1':
      result.setDate(result.getDate() + frequency * steps);
      return result;
    case '2':
      result.setDate(result.getDate() + 7 * frequency * steps);
      return result;
    case '3':
    case '4': {
      const months = (String(cycle) === '4' ? 12 : 1) * frequency * steps;
      const moved = addMonthsClamped(date, months);
      if (anchorDay) {
        const lastDay = new Date(moved.getFullYear(), moved.getMonth() + 1, 0).getDate();
        moved.setDate(Math.min(anchorDay, lastDay));
      }
      return moved;
    }
    default:
      return null;
  }
}

// The billing day the server will store (wallos_anchor_day_for_save()): the
// saved anchor while next_payment still sits on it, otherwise the day of
// next_payment, or the start day for a month-end or rolled-over date.
function formAnchorDay(next, start, cycle) {
  if (!next || (String(cycle) !== '3' && String(cycle) !== '4')) {
    return null;
  }
  const monthEnd = new Date(next.getFullYear(), next.getMonth() + 1, 0).getDate();
  const form = document.querySelector("#subs-form");
  const saved = form ? Number(form.dataset.anchorDay) || 0 : 0;
  if (saved >= 1 && saved <= 31 && Math.min(saved, monthEnd) === next.getDate()) {
    return saved;
  }
  let anchor = next.getDate();
  if (start) {
    const startDay = start.getDate();
    if (startDay > anchor && (anchor === monthEnd || (startDay >= 29 && anchor <= 3))) {
      anchor = startDay;
    }
  }
  return anchor;
}

function formSchedule() {
  const next = parseFormDate(document.querySelector("#next_payment").value);
  const start = parseFormDate(document.querySelector("#start_date").value);
  const cycle = document.querySelector("#cycle").value;
  const frequency = Number(document.querySelector("#frequency").value) || 1;
  const anchorDay = formAnchorDay(next, start, cycle);
  // Payments already made: scheduled dates from the start date up to the
  // next payment, as the server counts them (wallos_plan_fields()).
  let made = 0;
  if (next && start) {
    let previous = stepOccurrence(next, cycle, frequency, -1, anchorDay);
    while (previous && previous >= start && made < 10000) {
      made++;
      previous = stepOccurrence(next, cycle, frequency, -(made + 1), anchorDay);
    }
  }
  return { next, cycle, frequency, anchorDay, made };
}

function toggleEndsFields() {
  const mode = document.querySelector("#ends_mode").value;
  document.querySelector("#end-date-field").classList.toggle('hide', mode !== 'date');
  document.querySelector("#total-payments-field").classList.toggle('hide', mode !== 'count');
  document.querySelector("#end_date").required = mode === 'date';
  document.querySelector("#total_payments").required = mode === 'count';
  updateEndsHint();
}

// "Final payment Mar 31, 2027" for a count, or "12 payments from the next one" for a date.
function updateEndsHint() {
  const hint = document.querySelector("#ends-hint");
  if (!hint) {
    return;
  }
  const mode = document.querySelector("#ends_mode").value;
  const schedule = formSchedule();
  hint.textContent = '';
  if (!schedule.next || mode === 'never') {
    return;
  }
  if (mode === 'count') {
    const total = Number(document.querySelector("#total_payments").value);
    if (total >= 1) {
      const final = stepOccurrence(schedule.next, schedule.cycle, schedule.frequency, total - schedule.made - 1, schedule.anchorDay);
      if (final) {
        hint.textContent = hint.dataset.finalPayment.replace('%s', tallyFormatDate(final, 'long'));
      }
    }
    return;
  }
  const end = parseFormDate(document.querySelector("#end_date").value);
  if (!end) {
    return;
  }
  let count = 0;
  let current = schedule.next;
  while (current && current <= end && count < 10000) {
    count++;
    current = stepOccurrence(schedule.next, schedule.cycle, schedule.frequency, count, schedule.anchorDay);
  }
  hint.textContent = hint.dataset.paymentsRemaining.replace('%s', schedule.made + count);
}

function toggleVariableAmount() {
  const price = document.querySelector("#price");
  const variable = document.querySelector("#variable_amount").checked;
  price.placeholder = variable ? price.dataset.placeholderEstimate : price.dataset.placeholderPrice;
}

// Restarting the cycle on the day of payment only applies to manual renewals.
function toggleRenewFromAction() {
  const autoRenew = document.querySelector("#auto_renew");
  const group = document.querySelector("#renew-from-action-group");
  const isOneTime = document.querySelector("#cycle").value === '5';
  const manual = autoRenew && !autoRenew.checked && !isOneTime;
  group.classList.toggle('hide', !manual);
  if (!manual) {
    document.querySelector("#renew_from_action").checked = false;
  }
}

document.addEventListener('DOMContentLoaded', function () {
  ['#next_payment', '#start_date', '#frequency', '#cycle'].forEach((selector) => {
    const field = document.querySelector(selector);
    if (field) {
      field.addEventListener('change', updateEndsHint);
      field.addEventListener('change', updateCancelNoticeHint);
      field.addEventListener('change', updateIntroHint);
    }
  });
  if (document.querySelector("#cancel_notice_mode")) {
    toggleCancelNoticeFields(false);
  }
  if (document.querySelector("#intro_type")) {
    toggleIntroFields();
  }
  // A next payment or start date the user types is the new base for the
  // trial's cycles (events from code do not fire these listeners).
  ['#next_payment', '#start_date'].forEach((selector) => {
    const field = document.querySelector(selector);
    if (field) {
      field.addEventListener('input', () => { delete document.querySelector("#next_payment").dataset.trialBase; });
    }
  });
  if (document.querySelector("#ends_mode")) {
    toggleEndsFields();
    toggleRenewFromAction();
  }
});

/*
  Cancellation reminder: none, a fixed date, or a notice period before each
  renewal (mirrors includes/cancellation_deadline.php).
*/
function toggleCancelNoticeFields(fromUser) {
  const mode = document.querySelector("#cancel_notice_mode").value;
  document.querySelector("#cancellation-date-field").classList.toggle('hide', mode !== 'fixed');
  document.querySelector("#cancel-notice-field").classList.toggle('hide', mode !== 'before_renewal');
  document.querySelector("#min-term-field").classList.toggle('hide', mode !== 'before_renewal');
  document.querySelector("#cancellation_date").required = mode === 'fixed';
  const value = document.querySelector("#cancel_notice_value");
  value.required = mode === 'before_renewal';
  if (fromUser && mode === 'before_renewal' && value.value === '') {
    // Prefill the default notice from Settings.
    const group = document.querySelector("#cancel-notice-group");
    if (group.dataset.defaultValue !== '') {
      value.value = group.dataset.defaultValue;
      document.querySelector("#cancel_notice_unit").value = group.dataset.defaultUnit || 'months';
    }
  }
  updateCancelNoticeHint();
}

function subtractNotice(date, value, unit) {
  if (unit === 'months') {
    return addMonthsClamped(date, -value);
  }
  const result = new Date(date);
  result.setDate(result.getDate() - (unit === 'weeks' ? 7 * value : value));
  return result;
}

// "Cancel by Nov 12, 2026 to avoid the renewal on Dec 12, 2026"
function updateCancelNoticeHint() {
  const hint = document.querySelector("#cancel-notice-hint");
  if (!hint) {
    return;
  }
  hint.textContent = '';
  if (document.querySelector("#cancel_notice_mode").value !== 'before_renewal') {
    return;
  }
  const valueField = document.querySelector("#cancel_notice_value").value;
  const value = Number(valueField);
  const schedule = formSchedule();
  if (!schedule.next || valueField === '' || !Number.isInteger(value) || value < 0) {
    return;
  }
  const minTermEnd = parseFormDate(document.querySelector("#min_term_end").value);
  let renewal = schedule.next;
  let steps = 0;
  while (minTermEnd && renewal && renewal < minTermEnd && steps < 10000) {
    steps++;
    renewal = stepOccurrence(schedule.next, schedule.cycle, schedule.frequency, steps, schedule.anchorDay);
  }
  if (!renewal) {
    return;
  }
  const cancelBy = subtractNotice(renewal, value, document.querySelector("#cancel_notice_unit").value);
  const format = (date) => tallyFormatDate(date, 'long');
  if (minTermEnd && schedule.next < minTermEnd) {
    hint.textContent = hint.dataset.locked
      .replace('%1$s', format(minTermEnd)).replace('%2$s', format(cancelBy)).replace('%3$s', format(renewal));
  } else {
    hint.textContent = hint.dataset.cancelBy.replace('%1$s', format(cancelBy)).replace('%2$s', format(renewal));
  }
}

/*
  Free trial or intro price (mirrors includes/intro_pricing.php): N billing
  cycles from the start date, or until a date that is the first payment at
  the regular price. For a trial that date is also the next payment.
*/
function toggleIntroFields() {
  const type = document.querySelector("#intro_type").value;
  const lengthMode = document.querySelector("#intro_length_mode").value;
  const active = type !== 'none';
  document.querySelector("#intro-price-field").classList.toggle('hide', type !== 'promo');
  document.querySelector("#intro-length-fields").classList.toggle('hide', !active);
  document.querySelector("#intro-notify-field").classList.toggle('hide', !active);
  document.querySelector("#intro-cycles-field").classList.toggle('hide', lengthMode !== 'cycles');
  document.querySelector("#intro-end-field").classList.toggle('hide', lengthMode !== 'date');
  document.querySelector("#intro_price").required = type === 'promo';
  document.querySelector("#intro_cycles").required = active && lengthMode === 'cycles';
  document.querySelector("#intro_end_date").required = active && lengthMode === 'date';
  updateIntroHint();
}

function introEndDate() {
  const type = document.querySelector("#intro_type").value;
  if (type === 'none') {
    return null;
  }
  if (document.querySelector("#intro_length_mode").value === 'date') {
    return parseFormDate(document.querySelector("#intro_end_date").value);
  }
  const cycles = Number(document.querySelector("#intro_cycles").value);
  if (!Number.isInteger(cycles) || cycles < 1) {
    return null;
  }
  const schedule = formSchedule();
  // Without a start date the cycles count from the next payment the user
  // entered, kept aside because a trial then overwrites #next_payment.
  const nextField = document.querySelector("#next_payment");
  if (!nextField.dataset.trialBase) {
    nextField.dataset.trialBase = nextField.value;
  }
  const from = parseFormDate(document.querySelector("#start_date").value) || parseFormDate(nextField.dataset.trialBase);
  if (!from) {
    return null;
  }
  const startAnchor = ['3', '4'].includes(String(schedule.cycle)) ? from.getDate() : null;
  return stepOccurrence(from, schedule.cycle, schedule.frequency, cycles, startAnchor);
}

// "Free until Dec 4, 2026; the first payment at the regular price is then."
function updateIntroHint() {
  const hint = document.querySelector("#intro-hint");
  if (!hint) {
    return;
  }
  hint.textContent = '';
  const type = document.querySelector("#intro_type").value;
  const end = introEndDate();
  if (!end) {
    return;
  }
  const formatted = tallyFormatDate(end, 'long');
  hint.textContent = (type === 'trial' ? hint.dataset.trial : hint.dataset.promo).replace('%s', formatted);
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  if (type === 'trial' && end >= today) {
    // A trial's next payment is the first paid one.
    const next = document.querySelector("#next_payment");
    const value = formatFormDate(end);
    if (next.value !== value) {
      if (!next.dataset.trialBase) {
        next.dataset.trialBase = next.value;
      }
      next.value = value;
      updateEndsHint();
      updateCancelNoticeHint();
    }
  }
}

/*
  Editing a subscription another household member shares: its currency,
  category, payer, payment method, reminders and sharing are the owner's,
  so those fields are shown but locked (the server keeps them as stored).
*/
function setSharedEditMode(subscription) {
  const shared = !!subscription && (subscription.access || 'owner') !== 'owner';
  document.querySelectorAll('.share-percent').forEach((field) => { field.disabled = shared; });
  ['#currency', '#category', '#payer_user', '#payment_method', '#visibility', '#notifications', '#notify_days_before',
    '#cancel_notice_mode', '#cancellation_date', '#cancel_notice_value', '#cancel_notice_unit', '#min_term_end',
    '#replacement_subscription_id'].forEach((selector) => {
    const field = document.querySelector(selector);
    if (field) {
      field.disabled = shared || (selector === '#notify_days_before' && !document.querySelector('#notifications').checked);
    }
  });
  // Without the owner's currency the prices were converted for display:
  // they are shown but kept as stored (readOnly, so they are still sent).
  const lockPrices = shared && subscription.price_editable === false;
  document.querySelector("#price").readOnly = lockPrices;
  document.querySelector("#intro_price").readOnly = lockPrices;
  ['#visibility-group', '#cancel-notice-group'].forEach((selector) => {
    const group = document.querySelector(selector);
    if (group) {
      group.classList.toggle('is-locked', shared);
    }
  });
  const deleteButton = document.querySelector("#deletesub");
  if (shared) {
    deleteButton.style = 'display: none';
  }
}

// Price change notice: while editing, a change of price, currency, cycle or
// frequency shows what it does per month ("−€10.00/mo (−€120.00/yr)") and
// asks whether the provider changed the price or the user changed plan.
function priceChangeMonthly(cycle, frequency, price) {
  const every = Math.max(1, Number(frequency) || 1);
  switch (Number(cycle)) {
    case 1: return price * (30 / every);
    case 2: return price * (4.35 / every);
    case 3: return price / every;
    case 4: return price / (12 * every);
  }
  return null;
}

function priceChangeFormat(amount, currencyCode) {
  const option = document.querySelector(`#currency option[data-code="${CSS.escape(currencyCode || '')}"]`);
  return tallyFormatMoney(amount, currencyCode, option ? option.dataset.decimalsSetting : '', option ? option.dataset.symbol : '');
}

function rememberPriceForChange(subscription) {
  const notice = document.querySelector("#price-change-notice");
  if (!notice) {
    return;
  }
  const form = document.querySelector("#subs-form");
  if (subscription && (subscription.access || 'owner') !== 'viewer') {
    form.dataset.originalPrice = subscription.price;
    form.dataset.originalCurrency = subscription.currency_id;
    form.dataset.originalCycle = subscription.cycle;
    form.dataset.originalFrequency = subscription.frequency;
  } else {
    delete form.dataset.originalPrice;
    delete form.dataset.originalCurrency;
    delete form.dataset.originalCycle;
    delete form.dataset.originalFrequency;
  }
  document.querySelector("#price_change_provider").checked = false;
  updatePriceChangeNotice();
}

function updatePriceChangeNotice() {
  const notice = document.querySelector("#price-change-notice");
  const form = document.querySelector("#subs-form");
  if (!notice || form.dataset.originalPrice === undefined) {
    if (notice) {
      notice.classList.add("hide");
    }
    return;
  }
  const price = Number(document.querySelector("#price").value);
  const currencySelect = document.querySelector("#currency");
  const currency = currencySelect.value;
  const cycle = document.querySelector("#cycle").value;
  const frequency = document.querySelector("#frequency").value;
  const oldPrice = Number(form.dataset.originalPrice);
  const changed = Math.abs(price - oldPrice) >= 0.005
    || String(currency) !== String(form.dataset.originalCurrency)
    || String(cycle) !== String(form.dataset.originalCycle)
    || String(frequency) !== String(form.dataset.originalFrequency);
  notice.classList.toggle("hide", !changed || document.querySelector("#price").value === '');
  if (!changed) {
    return;
  }

  const delta = document.querySelector("#price-change-delta");
  if (String(currency) !== String(form.dataset.originalCurrency)) {
    delta.textContent = notice.dataset.currencyChanged;
    return;
  }
  const oldMonthly = priceChangeMonthly(form.dataset.originalCycle, form.dataset.originalFrequency, oldPrice);
  const newMonthly = priceChangeMonthly(cycle, frequency, price);
  if (oldMonthly === null || newMonthly === null) {
    delta.textContent = notice.dataset.oneTime;
    return;
  }
  const code = currencySelect.selectedOptions[0] ? currencySelect.selectedOptions[0].dataset.code : '';
  const difference = newMonthly - oldMonthly;
  const sign = difference > 0 ? '+' : (difference < 0 ? '−' : '±');
  delta.textContent = `${sign}${priceChangeFormat(Math.abs(difference), code)}${notice.dataset.perMonth}`
    + ` (${sign}${priceChangeFormat(Math.abs(difference) * 12, code)}${notice.dataset.perYear})`;
  delta.classList.toggle("is-success", difference < 0);
  delta.classList.toggle("is-danger", difference > 0);
}

// The price inputs accept as many decimals as the selected currency shows
// (Settings > Currencies > Decimals): 0.0001 for BTC, 1 for COP set to 0.
function updatePriceStep() {
  const currencySelect = document.querySelector("#currency");
  const option = currencySelect ? currencySelect.selectedOptions[0] : null;
  const decimals = option ? Number(option.dataset.decimals) : 2;
  const step = decimals > 0 ? (1 / Math.pow(10, decimals)).toFixed(decimals) : "1";
  ["#price", "#intro_price"].forEach((selector) => {
    const field = document.querySelector(selector);
    if (field) {
      field.step = step;
    }
  });
}

document.addEventListener("DOMContentLoaded", function () {
  const currencySelect = document.querySelector("#currency");
  if (currencySelect) {
    currencySelect.addEventListener("change", updatePriceStep);
    updatePriceStep();
  }
  ["#price", "#currency", "#cycle", "#frequency"].forEach((selector) => {
    const field = document.querySelector(selector);
    if (field) {
      field.addEventListener("input", updatePriceChangeNotice);
      field.addEventListener("change", updatePriceChangeNotice);
    }
  });
});

/*
  Active months (issue #25): daily, weekly and monthly plans can bill in some
  months only. The mask matches subscriptions.active_months: bit 0 = January.
*/
function activeMonthInputs() {
  return Array.from(document.querySelectorAll('.month-chip-input'));
}

function setActiveMonths(mask) {
  const value = Number(mask) >= 1 && Number(mask) <= 4095 ? Number(mask) : 4095;
  activeMonthInputs().forEach((input) => {
    input.checked = (value & (1 << (Number(input.value) - 1))) !== 0;
  });
  toggleActiveMonthsGroup();
}

function formActiveMonthsMask() {
  if (!activeMonthsApply()) {
    return 4095;
  }
  const mask = activeMonthInputs().reduce((total, input) => input.checked ? total | (1 << (Number(input.value) - 1)) : total, 0);
  return mask === 0 ? 4095 : mask;
}

function activeMonthsApply() {
  const cycle = document.querySelector("#cycle");
  return cycle && ['1', '2', '3'].includes(String(cycle.value));
}

function formMonthIsActive(date) {
  return (formActiveMonthsMask() & (1 << date.getMonth())) !== 0;
}

function toggleActiveMonthsGroup() {
  const group = document.querySelector("#active-months-group");
  if (!group) {
    return;
  }
  const applies = activeMonthsApply();
  group.classList.toggle('hide', !applies);
  activeMonthInputs().forEach((input) => { input.disabled = !applies; });
  updateActiveMonthsHint();
}

function updateActiveMonthsHint() {
  const inputs = activeMonthInputs();
  if (inputs.length === 0) {
    return;
  }
  const none = activeMonthsApply() && !inputs.some((input) => input.checked);
  const hint = document.querySelector("#active-months-hint");
  if (hint) {
    if (!hint.dataset.info) {
      hint.dataset.info = hint.textContent;
    }
    hint.textContent = none ? hint.dataset.none : hint.dataset.info;
    hint.classList.toggle('is-danger', none);
  }
  inputs[0].setCustomValidity(none ? (hint ? hint.dataset.none : 'none') : '');
}

document.addEventListener("DOMContentLoaded", function () {
  const cycle = document.querySelector("#cycle");
  if (cycle) {
    cycle.addEventListener("change", toggleActiveMonthsGroup);
    toggleActiveMonthsGroup();
  }
});

/*
  Sharing & reimbursement (issue #34): how the cost splits between household
  members, and what part of it comes back. The hint shows the account
  holder's own cost ("Your share €2.50").
*/
function setSharingFields(subscription) {
  const shares = (subscription && subscription.shares) || {};
  document.querySelectorAll('.share-percent').forEach((field) => {
    const memberId = field.closest('.sharing-member').dataset.memberId;
    field.value = shares[memberId] !== undefined ? Number(shares[memberId]) : '';
  });
  const reimbursed = document.querySelector('#reimbursed_percent');
  if (reimbursed) {
    reimbursed.value = subscription && Number(subscription.reimbursed_percent) > 0 ? Number(subscription.reimbursed_percent) : '';
    document.querySelector('#reimbursed_reason').value = (subscription && subscription.reimbursed_reason) || '';
  }
  const group = document.querySelector('#sharing-group');
  if (group) {
    group.open = Object.keys(shares).length > 0 || (subscription && Number(subscription.reimbursed_percent) > 0);
  }
  updateSharingHint();
}

function splitSharesEqually() {
  const fields = Array.from(document.querySelectorAll('.share-percent')).filter((field) => !field.disabled);
  let chosen = fields.filter((field) => field.value !== '' && Number(field.value) > 0);
  if (chosen.length < 2) {
    chosen = fields;
  }
  fields.forEach((field) => { if (!chosen.includes(field)) field.value = ''; });
  const each = Math.floor(10000 / chosen.length) / 100;
  chosen.forEach((field, index) => {
    // The last share takes the rounding remainder so the total is exactly 100.
    field.value = index === chosen.length - 1 ? (100 - each * (chosen.length - 1)).toFixed(2) : each.toFixed(2);
  });
  updateSharingHint();
}

function updateSharingHint() {
  const hint = document.querySelector('#sharing-hint');
  if (!hint) {
    return;
  }
  if (!hint.dataset.info) {
    hint.dataset.info = hint.textContent;
  }
  const fields = Array.from(document.querySelectorAll('.share-percent'));
  const price = Number(document.querySelector('#price').value) || 0;
  const option = document.querySelector('#currency').selectedOptions[0];
  const money = (amount) => tallyFormatMoney(amount, option ? option.dataset.code : '', option ? option.dataset.decimalsSetting : '', option ? option.dataset.symbol : '');
  const used = fields.filter((field) => field.value !== '' && Number(field.value) > 0);
  const total = used.reduce((sum, field) => sum + Number(field.value), 0);
  fields.forEach((field) => {
    const amount = field.parentElement.querySelector('.share-amount');
    if (amount) {
      amount.textContent = field.value !== '' && Number(field.value) > 0 ? money(price * Number(field.value) / 100) : '';
    }
  });
  const invalid = used.length > 0 && Math.abs(total - 100) > 0.05;
  fields.forEach((field, index) => field.setCustomValidity(invalid && index === 0 ? hint.dataset.mustTotal : ''));
  hint.classList.toggle('is-danger', invalid);
  if (invalid) {
    hint.textContent = hint.dataset.mustTotal;
    return;
  }

  const reimbursed = Math.min(100, Math.max(0, Number(document.querySelector('#reimbursed_percent').value) || 0));
  const selfField = document.querySelector(`#share_${hint.dataset.selfMember}`);
  const payer = document.querySelector('#payer_user');
  let mine;
  if (used.length > 0) {
    mine = selfField && selfField.value !== '' ? Number(selfField.value) / 100 : 0;
  } else {
    mine = !payer || payer.value === hint.dataset.selfMember ? 1 : 0;
  }
  if (used.length === 0 && reimbursed === 0) {
    hint.textContent = hint.dataset.info;
    return;
  }
  hint.textContent = hint.dataset.yourShare.replace('%s', money(price * mine * (1 - reimbursed / 100)));
}

document.addEventListener("DOMContentLoaded", function () {
  ['#price', '#currency', '#payer_user'].forEach((selector) => {
    const field = document.querySelector(selector);
    if (field) {
      field.addEventListener('input', updateSharingHint);
      field.addEventListener('change', updateSharingHint);
    }
  });
});
