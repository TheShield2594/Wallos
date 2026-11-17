
// Shared loading/empty/error states for the logo & payment icon search popups
function showSearchState(container, state) {
  if (state === 'loading') {
    container.innerHTML = '<div class="search-state"><i class="fa-solid fa-spinner fa-spin"></i></div>';
    return;
  }

  const isError = state === 'error';
  const icon = isError ? 'fa-circle-exclamation' : 'fa-magnifying-glass';
  const message = isError
    ? translate('error_fetching_image_results').replace(/:\s*$/, '')
    : translate('no_results_found');

  const stateElement = document.createElement('div');
  stateElement.className = 'search-state' + (isError ? ' error' : '');
  stateElement.innerHTML = `<i class="fa-solid ${icon}"></i>`;
  stateElement.appendChild(document.createTextNode(message));

  container.innerHTML = '';
  container.appendChild(stateElement);
}

function toggleAccountMenu(event) {
  const menu = document.querySelector('#account-menu');
  if (!menu) {
    return;
  }
  event.stopPropagation();
  const trigger = event.currentTarget;
  const opening = !menu.classList.contains('is-open');
  document.querySelectorAll('[aria-controls="account-menu"]').forEach(function (button) {
    button.setAttribute('aria-expanded', 'false');
  });
  if (!opening) {
    menu.classList.remove('is-open');
    return;
  }
  // Anchor the menu to whichever button opened it (sidebar user row or the
  // avatar in the phone toolbar).
  const rect = trigger.getBoundingClientRect();
  const fromSidebar = trigger.classList.contains('sidebar-user');
  menu.style.left = fromSidebar ? rect.left + 'px' : '';
  menu.style.right = fromSidebar ? '' : (window.innerWidth - rect.right) + 'px';
  menu.style.top = fromSidebar ? '' : (rect.bottom + 6) + 'px';
  menu.style.bottom = fromSidebar ? (window.innerHeight - rect.top + 6) + 'px' : '';
  menu.classList.add('is-open');
  trigger.setAttribute('aria-expanded', 'true');
  const first = menu.querySelector('a');
  if (first && event.detail === 0) {
    first.focus();
  }
}

function closeAccountMenu() {
  const menu = document.querySelector('#account-menu');
  if (menu && menu.classList.contains('is-open')) {
    menu.classList.remove('is-open');
    document.querySelectorAll('[aria-controls="account-menu"]').forEach(function (button) {
      button.setAttribute('aria-expanded', 'false');
    });
  }
}

function showErrorMessage(message) {
  const toast = document.querySelector(".toast#errorToast");
  const closeIcon = document.querySelector(".close-error");
  const errorMessage = document.querySelector(".errorMessage");
  const progress = document.querySelector(".progress.error");
  let timer1, timer2;
  errorMessage.textContent = message;
  toast.classList.add("active");
  progress.classList.add("active");
  timer1 = setTimeout(() => {
    toast.classList.remove("active");
    closeIcon.removeEventListener("click", () => { });
  }, 5000);

  timer2 = setTimeout(() => {
    progress.classList.remove("active");
  }, 5300);

  closeIcon.addEventListener("click", () => {
    toast.classList.remove("active");

    setTimeout(() => {
      progress.classList.remove("active");
    }, 300);

    clearTimeout(timer1);
    clearTimeout(timer2);
    closeIcon.removeEventListener("click", () => { });
  });
}

function showSuccessMessage(message) {
  const toast = document.querySelector(".toast#successToast");
  const closeIcon = document.querySelector(".close-success");
  const successMessage = document.querySelector(".successMessage");
  const progress = document.querySelector(".progress.success");
  let timer1, timer2;
  successMessage.textContent = message;
  toast.classList.add("active");
  progress.classList.add("active");
  timer1 = setTimeout(() => {
    toast.classList.remove("active");
    closeIcon.removeEventListener("click", () => { });
  }, 5000);

  timer2 = setTimeout(() => {
    progress.classList.remove("active");
  }, 5300);

  closeIcon.addEventListener("click", () => {
    toast.classList.remove("active");

    setTimeout(() => {
      progress.classList.remove("active");
    }, 300);

    clearTimeout(timer1);
    clearTimeout(timer2);
    closeIcon.removeEventListener("click", () => { });
  });
}

function applySystemTheme() {
  const prefersDarkMode = window.matchMedia('(prefers-color-scheme: dark)').matches;
  const themePreference = prefersDarkMode ? 'dark' : 'light';
  const darkThemeCss = document.querySelector("#dark-theme");
  darkThemeCss.disabled = themePreference === 'light';

  // Preserve existing classes on the body tag
  const existingClasses = document.body.className.split(' ').filter(cls => cls !== 'dark' && cls !== 'light');
  document.body.className = [...existingClasses, themePreference].join(' ');

  document.cookie = `inUseTheme=${themePreference}; expires=Fri, 31 Dec 9999 23:59:59 GMT; SameSite=Lax`;
  const themeColorMetaTag = document.querySelector('meta[name="theme-color"]');
  themeColorMetaTag.setAttribute('content', themePreference === 'dark' ? '#171A20' : '#FFFFFF');
}

document.addEventListener('DOMContentLoaded', function () {

  const userLocale = navigator.language || navigator.languages[0];
  document.cookie = `user_locale=${userLocale}; expires=Fri, 31 Dec 9999 23:59:59 GMT; SameSite=Lax`;

  if (window.update_theme_settings) {
    applySystemTheme();
  }

  // Keep following the system appearance while the page is open.
  window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
    if (window.update_theme_settings) {
      applySystemTheme();
    }
  });

  document.addEventListener('click', function (event) {
    const menu = document.querySelector('#account-menu');
    if (menu && !menu.contains(event.target)) {
      closeAccountMenu();
    }
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      closeAccountMenu();
    }
  });
});

function getCookie(name) {
  const cookies = document.cookie.split(';');
  for (let cookie of cookies) {
    cookie = cookie.trim();
    if (cookie.startsWith(`${name}=`)) {
      return cookie.substring(name.length + 1);
    }
  }
  return null;
}
/*
  Date formatting that follows Settings > Display > Date format, the same
  table as includes/date_format.php. style: 'short' (year only when it is not
  the current year), 'long' (always the year) or 'month'.
*/
const TALLY_DATE_FORMATS = {
  dmy: { order: ['day', 'month', 'year'], sep: '/', numeric: true },
  mdy: { order: ['month', 'day', 'year'], sep: '/', numeric: true },
  iso: { order: ['year', 'month', 'day'], sep: '-', numeric: true, alwaysYear: true },
  d_mon_y: { order: ['day', 'month', 'year'], sep: ' ', numeric: false },
  mon_d_y: { order: ['month', 'day', 'year'], sep: ' ', numeric: false },
};

function tallyParseDate(value) {
  if (value instanceof Date) {
    return isNaN(value) ? null : value;
  }
  if (typeof value !== 'string' || value.length < 10) {
    return null;
  }
  const date = new Date(value.substring(0, 10) + 'T00:00:00');
  return isNaN(date) ? null : date;
}

function tallyFormatDate(value, style) {
  const date = tallyParseDate(value);
  if (!date) {
    return value || '';
  }
  style = style || 'short';
  const lang = window.lang || 'en';
  const withYear = style !== 'short' || date.getFullYear() !== new Date().getFullYear();
  const format = TALLY_DATE_FORMATS[window.tallyDateFormat];

  if (!format) {
    const options = style === 'month'
      ? { month: 'short', year: '2-digit' }
      : { day: 'numeric', month: 'short', year: withYear ? 'numeric' : undefined };
    try {
      return date.toLocaleDateString(lang, options);
    } catch (e) {
      return date.toLocaleDateString('en', options);
    }
  }

  const pad = (number) => String(number).padStart(2, '0');
  let monthName;
  try {
    monthName = date.toLocaleDateString(lang, { month: 'short' });
  } catch (e) {
    monthName = date.toLocaleDateString('en', { month: 'short' });
  }
  const parts = {
    day: format.numeric ? pad(date.getDate()) : String(date.getDate()),
    month: format.numeric ? pad(date.getMonth() + 1) : monthName,
    year: String(date.getFullYear()),
  };

  if (style === 'month') {
    if (window.tallyDateFormat === 'iso') {
      return parts.year + '-' + parts.month;
    }
    const year = parts.year.substring(2);
    return format.numeric ? parts.month + '/' + year : monthName + ' ' + year;
  }

  const fields = format.order.filter((field) => field !== 'year' || withYear || format.alwaysYear);
  if (window.tallyDateFormat === 'mon_d_y' && fields.includes('year')) {
    return parts.month + ' ' + parts.day + ', ' + parts.year;
  }
  return fields.map((field) => parts[field]).join(format.sep);
}
