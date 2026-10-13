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
  toggleOneTimeCycleUI(false);
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

  const currencySelect = document.querySelector("#currency");
  currencySelect.value = subscription.currency_id.toString();
  const frequencySelect = document.querySelector("#frequency");
  frequencySelect.value = subscription.frequency;
  const cycleSelect = document.querySelector("#cycle");
  cycleSelect.value = subscription.cycle;
  toggleOneTimeCycleUI(subscription.cycle == 5);
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
  const cancellationDate = document.querySelector("#cancellation_date");
  cancellationDate.value = subscription.cancellation_date;

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
        logoResults.scrollTo({ top: targetTop, behavior: 'smooth' });
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

function setSortOption(sortOption) {
  const daysToExpire = 30;
  const expirationDate = new Date();
  expirationDate.setDate(expirationDate.getDate() + daysToExpire);
  const cookieValue = encodeURIComponent(sortOption) + '; expires=' + expirationDate.toUTCString();
  document.cookie = 'sortOrder=' + cookieValue + '; SameSite=Lax';
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
  const clear = document.querySelector('#clear-filters');
  if (clear && (activeFilters['categories'].length || activeFilters['members'].length || activeFilters['payments'].length || activeFilters['state'] !== '')) {
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
  const notificationsCheckbox = document.querySelector("#notifications");

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
  while (nextDate <= today && safetyCounter < maxIterations) {
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

