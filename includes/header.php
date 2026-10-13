<?php
require_once 'connect.php';
require_once 'checkuser.php';
require_once 'checksession.php';
require_once 'checkredirect.php';
require_once 'currency_formatter.php';

require_once 'libs/csrf.php';

require_once 'i18n/languages.php';
require_once 'i18n/getlang.php';
require_once 'i18n/' . $lang . '.php';

require_once 'getsettings.php';

require_once 'version.php';

if ($userCount == 0) {
  $db->close();
  header("Location: registration.php");
  exit();
}

$demoMode = getenv('DEMO_MODE');

$theme = "automatic";
if (isset($settings['theme'])) {
  $theme = $settings['theme'];
}

$updateThemeSettings = false;
if (isset($settings['update_theme_setttings'])) {
  $updateThemeSettings = $settings['update_theme_setttings'];
}

$colorTheme = "blue";
if (isset($settings['color_theme'])) {
  $colorTheme = $settings['color_theme'];
}

$customCss = "";
if (isset($settings['customCss'])) {
  $customCss = $settings['customCss'];
}

if (isset($themeValue)) {
  $cookieExpire = time() + (30 * 24 * 60 * 60);
  setcookie('theme', $themeValue, [
    'expires' => $cookieExpire,
    'samesite' => 'Lax'
  ]);
}

$isAdmin = $_SESSION['userId'] == 1;

$locale = isset($_COOKIE['user_locale']) ? $_COOKIE['user_locale'] : 'en_US';
$formatter = new IntlDateFormatter(
  $locale, 
  IntlDateFormatter::MEDIUM,
  IntlDateFormatter::NONE
);

function hex2rgb($hex)
{
  $hex = str_replace("#", "", $hex);
  if (strlen($hex) == 3) {
    $r = hexdec(substr($hex, 0, 1) . substr($hex, 0, 1));
    $g = hexdec(substr($hex, 1, 1) . substr($hex, 1, 1));
    $b = hexdec(substr($hex, 2, 1) . substr($hex, 2, 1));
  } else {
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
  }
  return "$r, $g, $b";
}

$userDisplayName = trim(($userData['firstname'] ?? '') . ' ' . ($userData['lastname'] ?? ''));
if ($userDisplayName === '') {
  $userDisplayName = $userData['username'];
}

// Which page is being viewed, for the sidebar and tab bar highlight.
$page = basename($_SERVER['PHP_SELF']);
$activeCategoryFilter = ($page === 'subscriptions.php' && isset($_GET['category']) && ctype_digit((string) $_GET['category']))
  ? (int) $_GET['category'] : null;
$navItems = [
  ['href' => '.', 'page' => 'index.php', 'icon' => 'fa-table-cells-large', 'label' => translate('dashboard', $i18n)],
  ['href' => 'subscriptions.php', 'page' => 'subscriptions.php', 'icon' => 'fa-list-ul', 'label' => translate('subscriptions', $i18n)],
  ['href' => 'calendar.php', 'page' => 'calendar.php', 'icon' => 'fa-calendar-days', 'label' => translate('calendar', $i18n)],
  ['href' => 'stats.php', 'page' => 'stats.php', 'icon' => 'fa-chart-simple', 'label' => translate('stats', $i18n)],
];

// Sidebar: subscription count and categories in use (with active counts).
$navSubscriptionCount = 0;
$navCategories = [];
$navStmt = $db->prepare("SELECT c.id, c.name, COUNT(s.id) AS cnt FROM categories c
  JOIN subscriptions s ON s.category_id = c.id AND s.user_id = :userId AND s.inactive = 0
  WHERE c.user_id = :userId GROUP BY c.id ORDER BY c.`order` ASC");
$navStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$navResult = $navStmt->execute();
while ($navRow = $navResult->fetchArray(SQLITE3_ASSOC)) {
  $navCategories[] = $navRow;
}
$navCountStmt = $db->prepare("SELECT COUNT(*) FROM subscriptions WHERE user_id = :userId");
$navCountStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$navSubscriptionCount = (int) $navCountStmt->execute()->fetchArray(SQLITE3_NUM)[0];

/*
  Page toolbar: every page opens with one. On tablet/desktop it is the sticky
  bar at the top of the content column; on phones it becomes a large title
  with the actions underneath. Usage:
    <?php page_toolbar_start(translate('calendar', $i18n), $subtitle); ?>
      ...action buttons...
    <?php page_toolbar_end(); ?>
*/
function page_toolbar_start($title, $subtitle = '', $class = '')
{
  ?>
  <header class="page-toolbar <?= htmlspecialchars($class) ?>" id="page-toolbar">
    <div class="page-toolbar-title">
      <h1><?= htmlspecialchars($title) ?></h1>
      <?php if ($subtitle !== '') { ?>
        <p class="page-toolbar-subtitle"><?= htmlspecialchars($subtitle) ?></p>
      <?php } ?>
    </div>
    <button type="button" class="account-button compact-only" onClick="toggleAccountMenu(event)"
      aria-haspopup="menu" aria-controls="account-menu" aria-label="<?= translate('profile', $GLOBALS['i18n']) ?>">
      <img src="<?= htmlspecialchars($GLOBALS['userData']['avatar'], ENT_QUOTES, 'UTF-8') ?>" alt="">
    </button>
    <div class="page-toolbar-actions">
  <?php
}

function page_toolbar_end()
{
  ?>
    </div>
  </header>
  <?php
}

?>
<!DOCTYPE html>
<html dir="<?= $languages[$lang]['dir'] ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>Tally - Subscription Tracker</title>
  <meta name="apple-mobile-web-app-title" content="Tally">
  <meta name="theme-color" content="<?= $theme == "light" ? "#FFFFFF" : "#171A20" ?>" id="theme-color" />
  <meta name="referrer" content="no-referrer">
  <link rel="icon" href="images/icon/favicon.ico" sizes="any">
  <link rel="icon" href="images/brand/tally-icon.svg" type="image/svg+xml">
  <link rel="apple-touch-icon" href="images/icon/apple-touch-icon.png">
  <link rel="apple-touch-icon" sizes="152x152" href="images/icon/apple-touch-icon-152.png">
  <link rel="apple-touch-icon" sizes="180x180" href="images/icon/apple-touch-icon-180.png">
  <link rel="manifest" href="manifest.php" crossorigin="use-credentials">
  <link rel="stylesheet" href="styles/theme.css?<?= $version ?>">
  <link rel="stylesheet" href="styles/styles.css?<?= $version ?>">
  <link rel="stylesheet" href="styles/dark-theme.css?<?= $version ?>" id="dark-theme" <?= $theme != "dark" ? "disabled" : "" ?>>
  <link rel="stylesheet" href="styles/themes/red.css?<?= $version ?>" id="red-theme" <?= $colorTheme != "red" ? "disabled" : "" ?>>
  <link rel="stylesheet" href="styles/themes/green.css?<?= $version ?>" id="green-theme" <?= $colorTheme != "green" ? "disabled" : "" ?>>
  <link rel="stylesheet" href="styles/themes/yellow.css?<?= $version ?>" id="yellow-theme" <?= $colorTheme != "yellow" ? "disabled" : "" ?>>
  <link rel="stylesheet" href="styles/themes/purple.css?<?= $version ?>" id="purple-theme" <?= $colorTheme != "purple" ? "disabled" : "" ?>>
  <link rel="stylesheet" href="styles/font-awesome.min.css">
  <link rel="stylesheet" href="styles/brands.css">
  <script type="text/javascript" src="scripts/all.js?<?= $version ?>"></script>
  <script type="text/javascript" src="scripts/common.js?<?= $version ?>"></script>
  <script type="text/javascript">
    window.theme = <?= json_encode($theme, JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS) ?>;
    window.update_theme_settings = "<?= $updateThemeSettings ?>";
    window.lang = "<?= $lang ?>";
    window.colorTheme = <?= json_encode($colorTheme, JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS) ?>;
    window.csrfToken = "<?= htmlspecialchars(generate_csrf_token()) ?>";
  </script>
  <style>
    <?= htmlspecialchars($customCss, ENT_QUOTES, 'UTF-8') ?>
  </style>
  <?php
  if (isset($settings['customColors'])) {
    ?>
    <style id="custom_theme_colors">
      :root, body.dark {
        <?php if (isset($settings['customColors']['main_color']) && !empty($settings['customColors']['main_color'])): ?>
          --main-color:
            <?= $settings['customColors']['main_color'] ?>
          ;
          --main-color-rgb:
            <?= hex2rgb($settings['customColors']['main_color']) ?>
          ;
        <?php endif; ?>
        <?php if (isset($settings['customColors']['accent_color']) && !empty($settings['customColors']['accent_color'])): ?>
          --accent-color:
            <?= $settings['customColors']['accent_color'] ?>
          ;
          --accent-color-rgb:
            <?= hex2rgb($settings['customColors']['accent_color']) ?>
          ;
        <?php endif; ?>
        <?php if (isset($settings['customColors']['hover_color']) && !empty($settings['customColors']['hover_color'])): ?>
          --hover-color:
            <?= $settings['customColors']['hover_color'] ?>
          ;
          --hover-color-rgb:
            <?= hex2rgb($settings['customColors']['hover_color']) ?>
          ;
        <?php endif; ?>
      }
    </style>
    <?php
  }
  ?>
  <script type="text/javascript" src="scripts/i18n/<?= $lang ?>.js?<?= $version ?>"></script>
  <script type="text/javascript" src="scripts/i18n/getlang.js?<?= $version ?>"></script>
  <script type="text/javascript" src="scripts/password-toggle.js?<?= $version ?>"></script>
  <script>
    if ('serviceWorker' in navigator && navigator.serviceWorker.controller) {
      if (!sessionStorage.getItem('sw_prefetched')) {
        navigator.serviceWorker.controller.postMessage({ type: 'PREFETCH_PAGES' });
        sessionStorage.setItem('sw_prefetched', '1');
      }
    }
  </script>
</head>

<body class="<?= $theme ?> <?= $languages[$lang]['dir'] ?>">
  <div class="app" data-page="<?= htmlspecialchars(basename($page, '.php')) ?>">
    <nav class="sidebar" aria-label="<?= translate('dashboard', $i18n) ?>">
      <a href="." class="sidebar-logo" title="Tally - Subscription Tracker">
        <?php include "images/siteicons/svg/logo.php"; ?>
      </a>
      <?php foreach ($navItems as $navItem) {
        $isCurrent = $page === $navItem['page'] && !($navItem['page'] === 'subscriptions.php' && $activeCategoryFilter !== null);
        ?>
        <a href="<?= $navItem['href'] ?>" class="sidebar-item<?= $isCurrent ? ' is-current' : '' ?>"
          <?= $isCurrent ? 'aria-current="page"' : '' ?>>
          <i class="fa-solid <?= $navItem['icon'] ?>" aria-hidden="true"></i>
          <span class="sidebar-item-label"><?= $navItem['label'] ?></span>
          <?php if ($navItem['page'] === 'subscriptions.php') { ?>
            <span class="sidebar-item-count"><?= $navSubscriptionCount ?></span>
          <?php } ?>
        </a>
      <?php } ?>

      <?php if (!empty($navCategories)) { ?>
        <div class="sidebar-heading"><?= translate('categories', $i18n) ?></div>
        <?php foreach ($navCategories as $navCategory) {
          $isCurrent = $activeCategoryFilter === (int) $navCategory['id'];
          $categoryName = $navCategory['id'] == 1 ? translate('no_category', $i18n) : $navCategory['name'];
          ?>
          <a href="<?= $isCurrent ? 'subscriptions.php' : 'subscriptions.php?category=' . (int) $navCategory['id'] ?>"
            class="sidebar-item sidebar-category<?= $isCurrent ? ' is-current' : '' ?>" <?= $isCurrent ? 'aria-current="page"' : '' ?>>
            <span class="sidebar-dot" style="--dot-hue: <?= ((int) $navCategory['id'] * 47) % 360 ?>" aria-hidden="true"></span>
            <span class="sidebar-item-label"><?= htmlspecialchars($categoryName) ?></span>
            <span class="sidebar-item-count"><?= (int) $navCategory['cnt'] ?></span>
          </a>
        <?php } ?>
      <?php } ?>

      <div class="sidebar-spacer"></div>
      <a href="settings.php" class="sidebar-item<?= $page === 'settings.php' ? ' is-current' : '' ?>"
        <?= $page === 'settings.php' ? 'aria-current="page"' : '' ?>>
        <i class="fa-solid fa-gear" aria-hidden="true"></i>
        <span class="sidebar-item-label"><?= translate('settings', $i18n) ?></span>
      </a>
      <button type="button" class="sidebar-user" onClick="toggleAccountMenu(event)" aria-haspopup="menu"
        aria-controls="account-menu">
        <img src="<?= htmlspecialchars($userData['avatar'], ENT_QUOTES, 'UTF-8') ?>" alt="" id="avatar">
        <span class="sidebar-user-text">
          <span class="sidebar-user-name" id="user"><?= htmlspecialchars($userDisplayName) ?></span>
          <span class="sidebar-user-role"><?= $isAdmin ? translate('admin', $i18n) : htmlspecialchars($userData['username']) ?></span>
        </span>
        <i class="fa-solid fa-ellipsis" aria-hidden="true"></i>
      </button>
    </nav>

    <div class="account-menu" id="account-menu" role="menu">
      <a href="profile.php" role="menuitem"<?= $page === 'profile.php' ? ' aria-current="page"' : '' ?>>
        <i class="fa-solid fa-circle-user" aria-hidden="true"></i><?= translate('profile', $i18n) ?></a>
      <?php if ($isAdmin) { ?>
        <a href="admin.php" role="menuitem"<?= $page === 'admin.php' ? ' aria-current="page"' : '' ?>>
          <i class="fa-solid fa-user-shield" aria-hidden="true"></i><?= translate('admin', $i18n) ?></a>
      <?php } ?>
      <a href="about.php" role="menuitem"<?= $page === 'about.php' ? ' aria-current="page"' : '' ?>>
        <i class="fa-solid fa-circle-info" aria-hidden="true"></i><?= translate('about', $i18n) ?></a>
      <?php if ($settings['disableLogin'] == 0) { ?>
        <a href="logout.php" role="menuitem" class="danger">
          <i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i><?= translate('logout', $i18n) ?></a>
      <?php } ?>
    </div>

    <nav class="tab-bar" aria-label="<?= translate('dashboard', $i18n) ?>">
      <?php foreach (array_merge($navItems, [['href' => 'settings.php', 'page' => 'settings.php', 'icon' => 'fa-gear', 'label' => translate('settings', $i18n)]]) as $navItem) {
        $isCurrent = $page === $navItem['page'];
        ?>
        <a href="<?= $navItem['href'] ?>" class="tab-bar-item<?= $isCurrent ? ' is-current' : '' ?>"
          <?= $isCurrent ? 'aria-current="page"' : '' ?>>
          <i class="fa-solid <?= $navItem['icon'] ?>" aria-hidden="true"></i>
          <span><?= $navItem['label'] ?></span>
        </a>
      <?php } ?>
    </nav>

    <div class="app-content">
  <main>