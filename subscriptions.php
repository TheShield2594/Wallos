<?php

require_once 'includes/header.php';
require_once 'includes/getdbkeys.php';
require_once 'includes/logo_theme_variant.php';

include_once 'includes/list_subscriptions.php';
require_once 'includes/ui_helpers.php';
require_once 'includes/households.php';

$householdMembership = wallos_household_membership($db, $userId);

$sort = "next_payment";
$sortOrder = $sort;

if ($settings['disabledToBottom'] === 'true') {
  $sql = "SELECT * FROM subscriptions WHERE user_id = :userId ORDER BY inactive ASC, next_payment ASC";
} else {
  $sql = "SELECT * FROM subscriptions WHERE user_id = :userId ORDER BY next_payment ASC, inactive ASC";
}

$params = array();

if (isset($_COOKIE['sortOrder']) && $_COOKIE['sortOrder'] != "") {
  $sort = $_COOKIE['sortOrder'] ?? 'next_payment';
}

$allowedSortCriteria = ['name', 'id', 'next_payment', 'price', 'payer_user_id', 'category_id', 'payment_method_id', 'inactive', 'alphanumeric', 'renewal_type', 'cancellation_date'];
// A link can pick the sort (e.g. the dashboard's "View all" cancellations);
// the page stores it in the cookie so the list keeps it after a refresh.
if (isset($_GET['sort']) && in_array($_GET['sort'], $allowedSortCriteria, true)) {
  $sort = $_GET['sort'];
}

$sortOrder = $sort;
$order = ($sort == "price" || $sort == "id") ? "DESC" : "ASC";

if ($sort == "alphanumeric") {
  $sort = "name";
}

if (!in_array($sort, $allowedSortCriteria)) {
  $sort = "next_payment";
}

if ($sort == "renewal_type") {
  $sort = "auto_renew";
}

// Cancel-by dates are computed per row; SQL orders by the next payment and
// printSubscriptions() re-sorts.
if ($sort == "cancellation_date") {
  $sort = "next_payment";
}

$sql = "SELECT * FROM subscriptions WHERE user_id = :userId";

if (isset($_GET['member'])) {
  $memberIds = explode(',', $_GET['member']);
  $placeholders = array_map(function ($key) {
    return ":member{$key}";
  }, array_keys($memberIds));

  $sql .= " AND payer_user_id IN (" . implode(',', $placeholders) . ")";

  foreach ($memberIds as $key => $memberId) {
    $params[":member{$key}"] = $memberId;
  }
}

if (isset($_GET['category'])) {
  $categoryIds = explode(',', $_GET['category']);
  $placeholders = array_map(function ($key) {
    return ":category{$key}";
  }, array_keys($categoryIds));

  $sql .= " AND category_id IN (" . implode(',', $placeholders) . ")";

  foreach ($categoryIds as $key => $categoryId) {
    $params[":category{$key}"] = $categoryId;
  }
}

if (isset($_GET['payment'])) {
  $paymentIds = explode(',', $_GET['payment']);
  $placeholders = array_map(function ($key) {
    return ":payment{$key}";
  }, array_keys($paymentIds));

  $sql .= " AND payment_method_id IN (" . implode(',', $placeholders) . ")";

  foreach ($paymentIds as $key => $paymentId) {
    $params[":payment{$key}"] = $paymentId;
  }
}

$notificationFilter = wallos_notification_filter_types($_GET['notifications'] ?? '');
if ($notificationFilter !== []) {
  $sql .= " AND (" . implode(' OR ', array_map('wallos_notification_filter_condition', $notificationFilter)) . ")";
}

if (!isset($settings['hideDisabledSubscriptions']) || $settings['hideDisabledSubscriptions'] !== 'true') {
  if (isset($_GET['state']) && $_GET['state'] != "") {
    $sql .= " AND inactive = :inactive";
    $params[':inactive'] = $_GET['state'];
  }
}

$orderByClauses = [];

if ($settings['disabledToBottom'] === 'true') {
  if (in_array($sort, ["payer_user_id", "category_id", "payment_method_id"])) {
    $orderByClauses[] = "$sort $order";
    $orderByClauses[] = "inactive ASC";
  } else {
    $orderByClauses[] = "inactive ASC";
    $orderByClauses[] = "$sort $order";
  }
} else {
  $orderByClauses[] = "$sort $order";
  if ($sort != "inactive") {
    $orderByClauses[] = "inactive ASC";
  }
}

if ($sort != "next_payment") {
  $orderByClauses[] = "next_payment ASC";
}

$sql .= " ORDER BY " . implode(", ", $orderByClauses);

$stmt = $db->prepare($sql);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);

if (!empty($params)) {
  foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value, SQLITE3_INTEGER);
  }
}

$result = $stmt->execute();
if ($result) {
  $subscriptions = array();
  while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $subscriptions[] = $row;
  }
}

// Subscriptions shared with the account's household join the list unless it
// is filtered by the account's own categories, members or payment methods.
if (!isset($_GET['member']) && !isset($_GET['category']) && !isset($_GET['payment'])) {
  $stateFilter = (!isset($settings['hideDisabledSubscriptions']) || $settings['hideDisabledSubscriptions'] !== 'true') ? ($_GET['state'] ?? null) : null;
  $subscriptions = mergeSharedSubscriptions($subscriptions ?? [], $db, $userId, $sort, $order, $settings['disabledToBottom'] === 'true', $stateFilter,
    fn($row) => wallos_notification_filter_matches($row, $notificationFilter));
}

foreach ($subscriptions as $subscription) {
  if (!empty($subscription['shared_owner_name'])) {
    // Someone else's records; they are not ours to count.
    continue;
  }
  // API-created rows can leave payer, category or payment method unset.
  $memberId = $subscription['payer_user_id'];
  if (isset($memberId, $members[$memberId])) {
    $members[$memberId]['count']++;
  }
  $categoryId = $subscription['category_id'];
  if (isset($categoryId, $categories[$categoryId])) {
    $categories[$categoryId]['count']++;
  }
  $paymentMethodId = $subscription['payment_method_id'];
  if (isset($paymentMethodId, $payment_methods[$paymentMethodId])) {
    $payment_methods[$paymentMethodId]['count']++;
  }
}

if ($sortOrder == "category_id") {
  usort($subscriptions, function ($a, $b) use ($categories) {
    return ($categories[$a['category_id']]['order'] ?? PHP_INT_MAX) <=> ($categories[$b['category_id']]['order'] ?? PHP_INT_MAX);
  });
}

if ($sortOrder == "payment_method_id") {
  usort($subscriptions, function ($a, $b) use ($payment_methods) {
    return ($payment_methods[$a['payment_method_id']]['order'] ?? PHP_INT_MAX) <=> ($payment_methods[$b['payment_method_id']]['order'] ?? PHP_INT_MAX);
  });
}

$hasSubscriptions = count($subscriptions) > 0;

// Toolbar title: a single category picked from the sidebar names the page.
$pageTitle = translate('subscriptions', $i18n);
if (isset($_GET['category']) && ctype_digit((string) $_GET['category']) && isset($categories[(int) $_GET['category']])) {
  $pageTitle = (int) $_GET['category'] === 1 ? translate('no_category', $i18n) : htmlspecialchars_decode($categories[(int) $_GET['category']]['name']);
}


$print = [];
foreach ($subscriptions as $subscription) {
  if ($subscription['inactive'] == 1 && isset($settings['hideDisabledSubscriptions']) && $settings['hideDisabledSubscriptions'] === 'true') {
    continue;
  }
  $id = $subscription['id'];
  $print[$id]['id'] = $id;
  $print[$id]['logo'] = $subscription['logo'] != "" ? "images/uploads/logos/" . $subscription['logo'] : "";
  $print[$id]['logo_text_color'] = $subscription['logo_text_color'] ?? null;
  $print[$id]['logo_variant'] = !empty($subscription['logo_variant']) ? "images/uploads/logos/" . $subscription['logo_variant'] : null;
  $print[$id]['name'] = $subscription['name'];
  $cycle = $subscription['cycle'];
  $frequency = $subscription['frequency'];
  $print[$id]['billing_cycle'] = getBillingCycle($cycle, $frequency, $i18n);
  $print[$id]['one_time'] = ($cycle == 5);
  $paymentMethodId = $subscription['payment_method_id'];
  $print[$id]['currency_code'] = $currencies[$subscription['currency_id']]['code'];
  $currencyId = $subscription['currency_id'];
  $print[$id]['auto_renew'] = $subscription['auto_renew'];
  $print[$id]['next_payment'] = tally_format_date($subscription['next_payment'], 'long', $lang);
  $print[$id]['payment_method_name'] = $payment_methods[$paymentMethodId]['name'] ?? '';
  $print[$id]['payment_method_id'] = $paymentMethodId;
  $print[$id]['category_id'] = $subscription['category_id'];
  $print[$id]['payer_user_id'] = $subscription['payer_user_id'];
  // The price that applies today: the intro price during a free trial or
  // promo, with the regular price shown struck through next to it.
  $print[$id]['price'] = wallos_current_price($subscription);
  $print[$id]['regular_price'] = floatval($subscription['price']);
  $print[$id]['progress'] = getSubscriptionProgress($cycle, $frequency, $subscription['next_payment']);
  $print[$id]['inactive'] = $subscription['inactive'];
  addListRowFields($print[$id], $subscription, $mainCurrencyId, $db);
  addSharedRowFields($print[$id], $subscription);

  if (isset($settings['convertCurrency']) && $settings['convertCurrency'] === 'true' && $currencyId != $mainCurrencyId) {
    $print[$id]['price'] = getPriceConverted($print[$id]['price'], $currencyId, $db);
    $print[$id]['regular_price'] = getPriceConverted($print[$id]['regular_price'], $currencyId, $db);
    $print[$id]['currency_code'] = $currencies[$mainCurrencyId]['code'];
  }
  if (isset($settings['showMonthlyPrice']) && $settings['showMonthlyPrice'] === 'true') {
    $print[$id]['price'] = getPricePerMonth($cycle, $frequency, $print[$id]['price'], wallos_cycle_active_months($subscription));
    $print[$id]['regular_price'] = getPricePerMonth($cycle, $frequency, $print[$id]['regular_price'], wallos_cycle_active_months($subscription));
  }
  // Sort on the price in the main currency, whether or not it is displayed
  // converted, so mixed currencies order by value rather than raw number.
  $sortPrice = $currencyId != $mainCurrencyId ? getPriceConverted(wallos_current_price($subscription), $currencyId, $db) : wallos_current_price($subscription);
  if (isset($settings['showMonthlyPrice']) && $settings['showMonthlyPrice'] === 'true') {
    $sortPrice = getPricePerMonth($cycle, $frequency, $sortPrice, wallos_cycle_active_months($subscription));
  }
  $print[$id]['sort_price'] = $sortPrice;
  if (isset($settings['showOriginalPrice']) && $settings['showOriginalPrice'] === 'true') {
    $print[$id]['original_price'] = wallos_current_price($subscription);
    $print[$id]['original_currency_code'] = $currencies[$subscription['currency_id']]['code'];
  }
}

if ($sortOrder == "alphanumeric") {
  usort($print, function ($a, $b) {
    return strnatcmp(strtolower($a['name']), strtolower($b['name']));
  });
}

$activeMonthlyTotal = 0;
foreach ($print as $printRow) {
  if (!$printRow['inactive'] && !$printRow['one_time']) {
    $activeMonthlyTotal += $printRow['monthly_main'];
  }
}
$pageSubtitle = sprintf(
  translate('subscriptions_subtitle', $i18n),
  count($print),
  CurrencyFormatter::format($activeMonthlyTotal, $currencies[$mainCurrencyId]['code'])
);

$googleSearchEnabled = false;
if ($db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='google_search'") > 0) {
  $googleSearchStmt = $db->prepare("SELECT COUNT(*) AS count FROM google_search WHERE user_id = :userId AND api_key != ''");
  $googleSearchStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
  $googleSearchResult = $googleSearchStmt->execute();
  if ($googleSearchResult) {
    $googleSearchRow = $googleSearchResult->fetchArray(SQLITE3_ASSOC);
    $googleSearchEnabled = $googleSearchRow && $googleSearchRow['count'] > 0;
  }
}
?>
<style>
  .logo-preview:after {
    content: '<?= translate('upload_logo', $i18n) ?>';
  }
</style>

<?php page_toolbar_start($pageTitle, $hasSubscriptions ? $pageSubtitle : ''); ?>
  <?php if ($hasSubscriptions) { ?>
    <label class="search-field" id="search-field">
      <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
      <input type="search" autocomplete="off" name="search" id="search" placeholder="<?= translate('search', $i18n) ?>"
        aria-label="<?= translate('search', $i18n) ?>" oninput="searchSubscriptions()" />
      <kbd class="compact-hide">/</kbd>
    </label>
    <div class="filtermenu">
      <button type="button" class="btn btn-icon" id="filtermenu-button" title="<?= translate("filter", $i18n) ?>"
        aria-label="<?= translate("filter", $i18n) ?>">
        <i class="fa-solid fa-filter" aria-hidden="true"></i>
      </button>
      <?php include 'includes/filters_menu.php'; ?>
    </div>
    <?php include 'includes/sort_options.php'; ?>
  <?php } ?>
  <button type="button" class="btn btn-primary btn-new" onClick="addSubscription()"
    aria-label="<?= translate('new_subscription', $i18n) ?>">
    <i class="fa-solid fa-plus" aria-hidden="true"></i>
    <span class="btn-new-label"><?= translate('new_subscription', $i18n) ?></span>
  </button>
<?php page_toolbar_end(); ?>

<div class="subs-layout<?= $hasSubscriptions ? '' : ' is-empty' ?>">
  <div class="subs-list">
  <div class="subscriptions" id="subscriptions">
    <?php
    if (!empty($print)) {
      printSubscriptions($print, $sortOrder === 'cancellation_date' ? 'cancellation_date' : $sort, $categories, $members, $i18n, $settings, $currencies, $mainCurrencyId, $lang);
    }

    if (!$hasSubscriptions) {
      ?>
      <div class="empty-state empty-page">
        <i class="fa-solid fa-receipt" aria-hidden="true"></i>
        <p class="empty-state-title"><?= translate('no_subscriptions_yet', $i18n) ?></p>
        <button type="button" class="btn btn-primary" onClick="addSubscription()">
          <?= translate('add_first_subscription', $i18n) ?>
        </button>
      </div>
      <?php
    }
    ?>
  </div>
  <div class="empty-state hide" id="search-no-results" role="status">
    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
    <p class="empty-state-title"><?= translate('no_results', $i18n) ?></p>
    <p><?= translate('no_results_hint', $i18n) ?></p>
  </div>
  </div>
  <?php require_once 'includes/subscription_details_popup.php'; ?>
</div>

<div class="sheet-backdrop" id="subscription-form-backdrop" onClick="closeAddSubscription()"></div>
<section class="subscription-form sheet" id="subscription-form" role="dialog" aria-modal="true" aria-labelledby="form-title">
  <header class="sheet-header">
    <h3 id="form-title"><?= translate('add_subscription', $i18n) ?></h3>
    <button type="button" class="sheet-close close-form" onClick="closeAddSubscription()" aria-label="<?= translate('cancel', $i18n) ?>">
      <i class="fa-solid fa-xmark" aria-hidden="true"></i>
    </button>
  </header>
  <form action="endpoints/subscription/add.php" method="post" id="subs-form">

    <div class="form-group-inline">
      <input type="text" id="name" name="name" autocomplete="off"
        placeholder="<?= translate('subscription_name', $i18n) ?>"
        onchange="setSearchButtonStatus()" onkeypress="this.onchange();" onpaste="this.onchange();"
        oninput="this.onchange();" required>
      <label for="logo" class="logo-preview">
        <img src="" alt="<?= translate('logo_preview', $i18n) ?>" id="form-logo">
      </label>
      <input type="file" id="logo" name="logo" accept="image/jpeg, image/png, image/gif, image/webp, image/svg+xml"
        onchange="handleFileSelect(event)" class="hidden-input">
      <input type="hidden" id="logo-url" name="logo-url">
      <div id="logo-search-button" class="image-button medium disabled" title="<?= translate('search_logo', $i18n) ?>"
        onClick="searchLogo()">
        <i class="fa-solid fa-magnifying-glass"></i>
      </div>
      <input type="hidden" id="id" name="id">
      <div class="logo-search-backdrop" id="logo-search-backdrop" onClick="closeLogoSearch()"></div>
      <div id="logo-search-results" class="logo-search"<?= !empty($googleSearchEnabled) ? ' data-google-search="1"' : '' ?>>
        <button type="button" class="close-logo-search" onClick="closeLogoSearch()" title="<?= translate('cancel', $i18n) ?>">
          <i class="fa-solid fa-xmark"></i>
        </button>
        <header>
          <h3 id="logo-search-title" data-title="<?= translate('web_search', $i18n) ?>">
            <?= translate('web_search', $i18n) ?>
          </h3>
        </header>
        <div class="logo-search-controls">
          <div class="logo-search-query">
            <input type="text" id="logo-search-query" autocomplete="off" inputmode="search"
              placeholder="<?= translate('search_logo', $i18n) ?>"
              aria-label="<?= translate('search_logo', $i18n) ?>">
            <button type="button" id="logo-search-submit" title="<?= translate('search', $i18n) ?>">
              <i class="fa-solid fa-magnifying-glass"></i>
            </button>
          </div>
          <div id="logo-search-nav" class="logo-search-nav"></div>
        </div>
        <div id="logo-search-images"></div>
      </div>
    </div>

    <div class="form-group-inline">
      <input type="number" step="0.01" min="0" id="price" name="price" autocomplete="off"
        placeholder="<?= translate('price', $i18n) ?>" data-placeholder-price="<?= translate('price', $i18n) ?>"
        data-placeholder-estimate="<?= translate('estimated_price', $i18n) ?>" required>
      <select id="currency" name="currency_id" placeholder="<?= translate('add_subscription', $i18n) ?>">
        <?php
        foreach ($currencies as $currency) {
          $selected = ($currency['id'] == $main_currency) ? 'selected' : '';
          ?>
          <option value="<?= $currency['id'] ?>" data-code="<?= htmlspecialchars($currency['code']) ?>"
            data-symbol="<?= htmlspecialchars($currency['symbol']) ?>"
            data-decimals="<?= CurrencyFormatter::inputDecimals($currency['code'], $currency['decimals'] ?? null) ?>"
            data-decimals-setting="<?= htmlspecialchars((string) ($currency['decimals'] ?? '')) ?>" <?= $selected ?>><?= $currency['name'] ?></option>
          <?php
        }
        ?>
      </select>
    </div>

    <div class="form-group">
      <input type="text" id="tier_name" name="tier_name" maxlength="60" autocomplete="off"
        placeholder="<?= translate('plan_tier_optional', $i18n) ?>" aria-label="<?= translate('plan_tier', $i18n) ?>">
    </div>

    <div class="price-change-notice hide" id="price-change-notice" role="status"
      data-per-month="<?= htmlspecialchars(translate('per_month_short', $i18n)) ?>"
      data-per-year="<?= htmlspecialchars(translate('per_year_short', $i18n)) ?>"
      data-currency-changed="<?= htmlspecialchars(translate('price_change_currency', $i18n)) ?>"
      data-one-time="<?= htmlspecialchars(translate('price_change_one_time', $i18n)) ?>">
      <p class="price-change-delta" id="price-change-delta"></p>
      <div class="form-group-inline">
        <input type="checkbox" id="price_change_provider" name="price_change_reason" value="provider">
        <label for="price_change_provider"><?= translate('price_change_from_provider', $i18n) ?></label>
      </div>
    </div>

    <div class="form-group">
      <div class="inline">
        <div class="split66">
          <label for="cycle"><?= translate('payment_every', $i18n) ?></label>
          <div class="inline">
            <select id="frequency" name="frequency" placeholder="<?= translate('frequency', $i18n) ?>">
              <?php
              for ($i = 1; $i <= 366; $i++) {
                ?>
                <option value="<?= $i ?>"><?= $i ?></option>
                <?php
              }
              ?>
            </select>
            <select id="cycle" name="cycle" placeholder="Cycle">
              <?php
              foreach ($cycles as $cycle) {
                ?>
                <option value="<?= $cycle['id'] ?>" <?= $cycle['id'] == 3 ? "selected" : "" ?>>
                  <?= translate(strtolower($cycle['name']), $i18n) ?>
                </option>
                <?php
              }
              ?>
            </select>
          </div>
        </div>
        <div class="split33" id="auto-renew-group">
          <label><?= translate('auto_renewal', $i18n) ?></label>
          <div class="inline height50">
            <input type="checkbox" id="auto_renew" name="auto_renew" checked onchange="toggleRenewFromAction()">
            <label for="auto_renew"><?= translate('automatically_renews', $i18n) ?></label>
          </div>
        </div>
      </div>
    </div>

    <div class="form-group" id="active-months-group">
      <fieldset class="month-chips-fieldset">
        <legend><?= translate('active_months', $i18n) ?></legend>
        <div class="month-chips">
          <?php
          $monthChipFormatter = null;
          try {
            $monthChipFormatter = new IntlDateFormatter($lang, IntlDateFormatter::NONE, IntlDateFormatter::NONE, null, null, 'LLL');
          } catch (Throwable $e) {
            $monthChipFormatter = null;
          }
          for ($month = 1; $month <= 12; $month++) {
            $monthDate = new DateTime(sprintf('2001-%02d-01', $month));
            $monthChipName = $monthChipFormatter ? $monthChipFormatter->format($monthDate) : $monthDate->format('M');
            ?>
            <input type="checkbox" class="month-chip-input" id="active_month_<?= $month ?>" name="active_months[]"
              value="<?= $month ?>" checked onchange="updateActiveMonthsHint()">
            <label class="month-chip" for="active_month_<?= $month ?>"><?= htmlspecialchars($monthChipName) ?></label>
            <?php
          }
          ?>
        </div>
      </fieldset>
      <p class="form-hint" id="active-months-hint" data-none="<?= translate('active_months_none', $i18n) ?>"><?= translate('active_months_info', $i18n) ?></p>
    </div>

    <div class="form-group">
      <div class="inline">
        <div class="split50">
          <label for="start_date"><?= translate('start_date', $i18n) ?></label>
          <div class="date-wrapper">
            <input type="date" id="start_date" name="start_date" autocomplete="off">
          </div>
        </div>
        <button type="button" id="autofill-next-payment-button"
          class="button secondary-button autofill-next-payment hideOnMobile"
          title="<?= translate('calculate_next_payment_date', $i18n) ?>" onClick="autoFillNextPaymentDate(event)">
          <i class="fa-solid fa-wand-magic-sparkles"></i>
        </button>
        <div class="split50">
          <label for="next_payment" class="split-label">
            <span id="next-payment-label-recurring"><?= translate('next_payment', $i18n) ?></span>
            <span id="next-payment-label-onetime" style="display:none"><?= translate('payment_date', $i18n) ?></span>
            <div id="autofill-next-payment-button" class="autofill-next-payment hideOnDesktop"
              title="<?= translate('calculate_next_payment_date', $i18n) ?>" onClick="autoFillNextPaymentDate(event)">
              <i class="fa-solid fa-wand-magic-sparkles"></i>
            </div>
          </label>
          <div class="date-wrapper">
            <input type="date" id="next_payment" name="next_payment" autocomplete="off" required>
          </div>
        </div>
      </div>
    </div>

    <div class="form-group" id="intro-group">
      <div class="inline">
        <div class="split50">
          <label for="intro_type"><?= translate('intro_period', $i18n) ?></label>
          <select id="intro_type" name="intro_type" onchange="toggleIntroFields()">
            <option value="none"><?= translate('none', $i18n) ?></option>
            <option value="trial"><?= translate('free_trial', $i18n) ?></option>
            <option value="promo"><?= translate('intro_price', $i18n) ?></option>
          </select>
        </div>
        <div class="split50 hide" id="intro-price-field">
          <label for="intro_price"><?= translate('intro_price_per_payment', $i18n) ?></label>
          <input type="number" step="0.01" min="0" id="intro_price" name="intro_price" autocomplete="off">
        </div>
      </div>
      <div class="inline intro-row hide" id="intro-length-fields">
        <div class="split50">
          <label for="intro_length_mode"><?= translate('intro_length', $i18n) ?></label>
          <select id="intro_length_mode" name="intro_length_mode" onchange="toggleIntroFields()">
            <option value="cycles"><?= translate('intro_length_cycles', $i18n) ?></option>
            <option value="date"><?= translate('intro_length_date', $i18n) ?></option>
          </select>
        </div>
        <div class="split50" id="intro-cycles-field">
          <label for="intro_cycles"><?= translate('billing_cycles', $i18n) ?></label>
          <input type="number" min="1" max="1000" step="1" id="intro_cycles" name="intro_cycles" autocomplete="off" oninput="updateIntroHint()">
        </div>
        <div class="split50 hide" id="intro-end-field">
          <label for="intro_end_date"><?= translate('first_full_price_payment', $i18n) ?></label>
          <div class="date-wrapper">
            <input type="date" id="intro_end_date" name="intro_end_date" autocomplete="off" onchange="updateIntroHint()">
          </div>
        </div>
      </div>
      <div class="intro-row hide" id="intro-notify-field">
        <label for="intro_notify_days"><?= translate('remind_me_before_it_ends', $i18n) ?></label>
        <select id="intro_notify_days" name="intro_notify_days">
          <option value="1">1 <?= translate('day_before', $i18n) ?></option>
          <?php for ($i = 2; $i <= 30; $i++) { ?>
            <option value="<?= $i ?>" <?= $i === 3 ? 'selected' : '' ?>><?= $i ?> <?= translate('days_before', $i18n) ?></option>
          <?php } ?>
        </select>
      </div>
      <p class="form-hint" id="intro-hint" aria-live="polite"
        data-trial="<?= htmlspecialchars(translate('trial_hint', $i18n), ENT_QUOTES) ?>"
        data-promo="<?= htmlspecialchars(translate('promo_hint', $i18n), ENT_QUOTES) ?>"></p>
    </div>

    <div class="form-group" id="ends-group">
      <div class="inline">
        <div class="split50">
          <label for="ends_mode"><?= translate('ends', $i18n) ?></label>
          <select id="ends_mode" name="ends_mode" onchange="toggleEndsFields()">
            <option value="never"><?= translate('ends_never', $i18n) ?></option>
            <option value="date"><?= translate('ends_on_date', $i18n) ?></option>
            <option value="count"><?= translate('ends_after_payments', $i18n) ?></option>
          </select>
        </div>
        <div class="split50 hide" id="end-date-field">
          <label for="end_date"><?= translate('final_payment', $i18n) ?></label>
          <div class="date-wrapper">
            <input type="date" id="end_date" name="end_date" autocomplete="off" onchange="updateEndsHint()">
          </div>
        </div>
        <div class="split50 hide" id="total-payments-field">
          <label for="total_payments"><?= translate('number_of_payments', $i18n) ?></label>
          <input type="number" id="total_payments" name="total_payments" min="1" max="10000" step="1" autocomplete="off" oninput="updateEndsHint()">
        </div>
      </div>
      <p class="form-hint" id="ends-hint" aria-live="polite"
        data-final-payment="<?= htmlspecialchars(translate('final_payment_on', $i18n), ENT_QUOTES) ?>"
        data-payments-remaining="<?= htmlspecialchars(translate('payments_remaining_from_next', $i18n), ENT_QUOTES) ?>"></p>
    </div>

    <div class="form-group-inline grow" id="variable-amount-group">
      <input type="checkbox" id="variable_amount" name="variable_amount" onchange="toggleVariableAmount()">
      <label for="variable_amount" class="grow"><?= translate('variable_amount', $i18n) ?></label>
    </div>

    <div class="form-group-inline grow" id="renew-from-action-group">
      <input type="checkbox" id="renew_from_action" name="renew_from_action">
      <label for="renew_from_action" class="grow"><?= translate('renewal_resets_cycle', $i18n) ?></label>
    </div>

    <div class="form-group">
      <div class="inline">
        <div class="split50">
          <label for="payment_method"><?= translate('payment_method', $i18n) ?></label>
          <select id="payment_method" name="payment_method_id">
            <?php
            foreach ($payment_methods as $payment) {
              ?>
              <option value="<?= $payment['id'] ?>">
                <?= $payment['name'] ?>
              </option>
              <?php
            }
            ?>
          </select>
        </div>
        <div class="split50">
          <label for="payer_user"><?= translate('paid_by', $i18n) ?></label>
          <select id="payer_user" name="payer_user_id">
            <?php
            foreach ($members as $member) {
              ?>
              <option value="<?= $member['id'] ?>"><?= $member['name'] ?></option>
              <?php
            }
            ?>
          </select>
        </div>
      </div>
    </div>

    <details class="form-group form-disclosure" id="sharing-group">
      <summary><?= translate('sharing_and_reimbursement', $i18n) ?></summary>
      <div class="sharing-fields">
        <?php if (count($members) > 1) { ?>
          <fieldset class="sharing-members">
            <legend><?= translate('split_with', $i18n) ?></legend>
            <?php foreach ($members as $member) { ?>
              <div class="sharing-member" data-member-id="<?= (int) $member['id'] ?>">
                <label for="share_<?= (int) $member['id'] ?>"><?= htmlspecialchars($member['name']) ?></label>
                <div class="inline">
                  <input type="number" id="share_<?= (int) $member['id'] ?>" name="shares[<?= (int) $member['id'] ?>]" class="share-percent"
                    min="0" max="100" step="0.01" inputmode="decimal" placeholder="%" aria-describedby="sharing-hint" oninput="updateSharingHint()">
                  <span class="share-amount" aria-live="off"></span>
                </div>
              </div>
            <?php } ?>
            <button type="button" class="button secondary-button thin" onclick="splitSharesEqually()"><?= translate('split_equally', $i18n) ?></button>
          </fieldset>
        <?php } ?>
        <div class="inline sharing-reimbursed">
          <div class="split33">
            <label for="reimbursed_percent"><?= translate('reimbursed_percent', $i18n) ?></label>
            <input type="number" id="reimbursed_percent" name="reimbursed_percent" min="0" max="100" step="0.01" inputmode="decimal"
              placeholder="0" oninput="updateSharingHint()">
          </div>
          <div class="split66">
            <label for="reimbursed_reason"><?= translate('reimbursed_reason', $i18n) ?></label>
            <input type="text" id="reimbursed_reason" name="reimbursed_reason" maxlength="80" autocomplete="off"
              placeholder="<?= translate('reimbursed_reason_placeholder', $i18n) ?>">
          </div>
        </div>
        <p class="form-hint" id="sharing-hint" data-your-share="<?= translate('your_share_amount', $i18n) ?>"
          data-must-total="<?= translate('shares_must_total', $i18n) ?>"
          data-self-member="<?= (int) array_key_first($members) ?>"><?= translate('sharing_info', $i18n) ?></p>
      </div>
    </details>

    <div class="form-group">
      <label for="category"><?= translate('category', $i18n) ?></label>
      <select id="category" name="category_id">
        <?php
        foreach ($categories as $category) {
          ?>
          <option value="<?= $category['id'] ?>">
            <?= $category['name'] ?>
          </option>
          <?php
        }
        ?>
      </select>
    </div>

    <div class="form-group-inline grow" id="notifications-group">
      <input type="checkbox" id="notifications" name="notifications" onchange="toggleNotificationDays()">
      <label for="notifications" class="grow"><?= translate('enable_notifications', $i18n) ?></label>
    </div>

    <div class="form-group" id="notify-days-cancellation-group">
      <label for="notify_days_before"><?= translate('notify_me', $i18n) ?></label>
      <select id="notify_days_before" name="notify_days_before" disabled>
        <option value="-1"><?= translate('default_value_from_settings', $i18n) ?></option>
        <option value="0"><?= translate('on_due_date', $i18n) ?></option>
        <option value="1">1 <?= translate('day_before', $i18n) ?></option>
        <?php
        for ($i = 2; $i <= 180; $i++) {
          ?>
          <option value="<?= $i ?>"><?= $i ?>   <?= translate('days_before', $i18n) ?></option>
          <?php
        }
        ?>
      </select>
    </div>

    <div class="form-group" id="cancel-notice-group"
      data-default-value="<?= isset($settings['default_cancel_notice_value']) && $settings['default_cancel_notice_value'] !== null ? (int) $settings['default_cancel_notice_value'] : '' ?>"
      data-default-unit="<?= htmlspecialchars($settings['default_cancel_notice_unit'] ?? 'months') ?>">
      <div class="inline">
        <div class="split50">
          <label for="cancel_notice_mode"><?= translate('cancellation_notification', $i18n) ?></label>
          <select id="cancel_notice_mode" name="cancel_notice_mode" onchange="toggleCancelNoticeFields(true)">
            <option value="none"><?= translate('cancel_notice_none', $i18n) ?></option>
            <option value="fixed"><?= translate('cancel_notice_fixed', $i18n) ?></option>
            <option value="before_renewal"><?= translate('cancel_notice_before_renewal', $i18n) ?></option>
          </select>
        </div>
        <div class="split50 hide" id="cancellation-date-field">
          <label for="cancellation_date"><?= translate('remind_me_on', $i18n) ?></label>
          <div class="date-wrapper">
            <input type="date" id="cancellation_date" name="cancellation_date" autocomplete="off">
          </div>
        </div>
        <div class="split50 hide" id="cancel-notice-field">
          <label for="cancel_notice_value"><?= translate('notice_period', $i18n) ?></label>
          <div class="inline">
            <input type="number" id="cancel_notice_value" name="cancel_notice_value" min="0" max="3650" step="1"
              autocomplete="off" oninput="updateCancelNoticeHint()">
            <select id="cancel_notice_unit" name="cancel_notice_unit" onchange="updateCancelNoticeHint()"
              aria-label="<?= translate('notice_period', $i18n) ?>">
              <option value="days"><?= translate('days', $i18n) ?></option>
              <option value="weeks"><?= translate('weeks', $i18n) ?></option>
              <option value="months"><?= translate('months', $i18n) ?></option>
            </select>
          </div>
        </div>
      </div>
      <div class="hide" id="min-term-field">
        <label for="min_term_end"><?= translate('minimum_term_until', $i18n) ?></label>
        <div class="date-wrapper">
          <input type="date" id="min_term_end" name="min_term_end" autocomplete="off" onchange="updateCancelNoticeHint()">
        </div>
      </div>
      <p class="form-hint" id="cancel-notice-hint" aria-live="polite"
        data-cancel-by="<?= htmlspecialchars(translate('cancel_by_to_avoid_renewal', $i18n), ENT_QUOTES) ?>"
        data-locked="<?= htmlspecialchars(translate('locked_in_until_cancel_by', $i18n), ENT_QUOTES) ?>"></p>
    </div>

    <?php if ($householdMembership !== null) { ?>
      <div class="form-group" id="visibility-group">
        <label for="visibility"><?= translate('visibility', $i18n) ?></label>
        <select id="visibility" name="visibility">
          <option value="private"><?= translate('visibility_private', $i18n) ?></option>
          <option value="household"><?= sprintf(translate('visibility_household', $i18n), htmlspecialchars($householdMembership['name'])) ?></option>
        </select>
      </div>
    <?php } ?>

    <div class="form-group">
      <input type="text" id="url" name="url" autocomplete="off" placeholder="<?= translate('url', $i18n) ?>">
    </div>

    <div class="form-group">
      <div class="notes-editor">
        <div class="notes-toolbar">
          <button type="button" class="notes-toolbar-button" title="<?= translate('notes_bold', $i18n) ?>" onClick="applyNotesMarkdown('bold')">
            <i class="fa-solid fa-bold"></i>
          </button>
          <button type="button" class="notes-toolbar-button" title="<?= translate('notes_italic', $i18n) ?>" onClick="applyNotesMarkdown('italic')">
            <i class="fa-solid fa-italic"></i>
          </button>
          <button type="button" class="notes-toolbar-button" title="<?= translate('notes_list', $i18n) ?>" onClick="applyNotesMarkdown('list')">
            <i class="fa-solid fa-list-ul"></i>
          </button>
          <button type="button" class="notes-toolbar-button" title="<?= translate('notes_link', $i18n) ?>" onClick="applyNotesMarkdown('link')">
            <i class="fa-solid fa-link"></i>
          </button>
        </div>
        <textarea id="notes" name="notes" class="thin" autocomplete="off" placeholder="<?= translate('notes', $i18n) ?>"></textarea>
      </div>
    </div>

    <div class="form-group">
      <div class="inline grow">
        <input type="checkbox" id="inactive" name="inactive" onchange="toggleReplacementSub()">
        <label for="inactive" class="grow"><?= translate('inactive', $i18n) ?></label>
      </div>
    </div>

    <?php
    $orderedSubscriptions = $subscriptions;
    usort($orderedSubscriptions, function ($a, $b) {
      return strnatcmp(strtolower($a['name']), strtolower($b['name']));
    });
    ?>

    <div class="form-group hide" id="replacement_subscritpion">
      <label for="replacement_subscription_id"><?= translate('replaced_with', $i18n) ?>:</label>
      <select id="replacement_subscription_id" name="replacement_subscription_id">
        <option value="0"><?= translate('none', $i18n) ?></option>
        <?php
        foreach ($orderedSubscriptions as $sub) {
          if ($sub['inactive'] == 0 && empty($sub['shared_owner_name'])) {
            ?>
            <option value="<?= htmlspecialchars($sub['id']) ?>"><?= htmlspecialchars($sub['name']) ?>
            </option>
            <?php
          }
        }
        ?>
      </select>
    </div>

    <div class="buttons">
      <input type="button" value="<?= translate('delete', $i18n) ?>" class="warning-button left thin" id="deletesub"
        style="display: none">
      <input type="button" value="<?= translate('cancel', $i18n) ?>" class="secondary-button thin"
        onClick="closeAddSubscription()">
      <input type="submit" value="<?= translate('save', $i18n) ?>" class="thin" id="save-button">
    </div>
  </form>
</section>

<script src="scripts/subscriptions.js?<?= $version ?>"></script>
<?php
if (isset($_GET['add'])) {
  ?>
  <script>
    addSubscription();
  </script>
  <?php
} elseif (isset($_GET['edit']) && ctype_digit((string) $_GET['edit'])) {
  ?>
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      openEditSubscription(new Event('open'), <?= (int) $_GET['edit'] ?>);
    });
  </script>
  <?php
}

require_once 'includes/footer.php';
?>
