function applySystemTheme() {
  const prefersDarkMode = window.matchMedia('(prefers-color-scheme: dark)').matches;
  const themePreference = prefersDarkMode ? 'dark' : 'light';
  const darkThemeCss = document.querySelector("#dark-theme");
  darkThemeCss.disabled = themePreference === 'light';
  document.body.className = themePreference;
  const themeColorMetaTag = document.querySelector('meta[name="theme-color"]');
  themeColorMetaTag.setAttribute('content', themePreference === 'dark' ? '#171A20' : '#FFFFFF');
}

document.addEventListener('DOMContentLoaded', function () {

  const userLocale = navigator.language || navigator.languages[0];
  document.cookie = `user_locale=${userLocale}; expires=Fri, 31 Dec 9999 23:59:59 GMT; SameSite=Lax`;

  if (window.update_theme_settings) {
    applySystemTheme();
  }

  // Keep following the system appearance until the user picks one.
  window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
    if (window.update_theme_settings) {
      applySystemTheme();
      if (typeof applyAuthThemeIcon === 'function') {
        applyAuthThemeIcon();
      }
    }
  });

});