<?php
require_once 'includes/header.php';
require_once 'includes/logo_theme_variant.php';
require_once 'includes/default_names.php';
require_once 'includes/households.php';
require_once 'includes/ui_helpers.php';

// Mine / Household: the household scope adds what other members share.
$statsHousehold = wallos_household_membership($db, $userId);
$statsScope = ($statsHousehold !== null && ($_GET['scope'] ?? '') === 'household') ? 'household' : 'mine';


// Get code of main currency to display on statistics
$query = "SELECT c.code, c.symbol, c.decimals
          FROM currencies c
          INNER JOIN user u ON c.id = u.main_currency
          WHERE u.id = :userId";
$stmt = $db->prepare($query);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
$row = $result->fetchArray(SQLITE3_ASSOC);
$code = $row['code'];
$mainCurrencySymbol = $row['symbol'] ?? '';
$mainCurrencyDecimals = CurrencyFormatter::sanitizeDecimals($row['decimals'] ?? null);

$statsCostModeEnabled = true;
require_once 'includes/stats_calculations.php';
// The filter menu re-sorts $categories into a list; the split views need them by id.
$categoriesById = $categories;
require_once 'includes/stats_extra_calculations.php';
require_once 'includes/upcoming_cancellations.php';

// Period: per month (normalized, the default), or the charges that actually
// fall in the calendar month, the pay period or a custom range.
require_once 'includes/period_stats.php';
$statsPeriod = wallos_stats_period_range($_GET['period'] ?? null, $_GET['from'] ?? null, $_GET['to'] ?? null,
  new DateTime('today'), $budgetPeriodType, $budgetPeriodAnchorDate);
$statsPeriodActive = $statsPeriod['period'] !== 'normalized';
$periodCharges = null;
if ($statsPeriodActive) {
  $periodCharges = wallos_period_charges($subscriptions ?? [], $statsPeriod['start'], $statsPeriod['end'],
    fn($amount, $currencyId) => getPriceConverted($amount, $currencyId, $db, $userId));
  $statsPeriodLabel = formatBudgetPeriodLabel($statsPeriod['start'], $statsPeriod['end']);
  $statsPeriodDays = (int) $statsPeriod['start']->diff($statsPeriod['end'])->format('%a') + 1;
  // Dates before today are projected from the schedule, not recorded payments.
  $statsPeriodIncludesPast = $statsPeriod['start'] < new DateTime('today');
}
$periodQuery = function (array $changes) {
  $query = array_merge($_GET, $changes);
  foreach ($query as $key => $value) {
    if ($value === null) {
      unset($query[$key]);
    }
  }
  return 'stats.php' . ($query ? '?' . http_build_query($query) : '');
};

// Subscriptions flagged for cancellation, and what cancelling them would stop costing.
$upcomingCancellations = get_upcoming_cancellations($db, $userId);
$upcomingCancellationsCount = count($upcomingCancellations);
$potentialMonthlySavings = get_upcoming_cancellations_monthly_value($upcomingCancellations, $db, $userId);

// What was actually paid, from the payment ledger: the last twelve months,
// and this year so far against the same months last year.
require_once 'includes/payment_ledger.php';
$ledgerToday = new DateTime('today');
$ledgerMonths = wallos_ledger_monthly_totals($db, $userId,
    (clone $ledgerToday)->modify('first day of this month')->modify('-23 months')->format('Y-m'),
    $ledgerToday->format('Y-m'));
$actualPaymentsDataPoints = [];
foreach (array_slice($ledgerMonths, -12, null, true) as $month => $total) {
    $actualPaymentsDataPoints[] = [
        'label' => tally_format_date($month . '-01', 'month', $lang),
        'y' => round($total, 2),
    ];
}
$showActualPaymentsGraph = array_sum(array_column($actualPaymentsDataPoints, 'y')) > 0;
$actualThisYear = 0.0;
$actualLastYear = 0.0;
$currentYear = $ledgerToday->format('Y');
$currentMonth = (int) $ledgerToday->format('n');
foreach ($ledgerMonths as $month => $total) {
    [$year, $monthNumber] = array_map('intval', explode('-', $month));
    if ($monthNumber > $currentMonth) {
        continue;
    }
    if ((string) $year === $currentYear) {
        $actualThisYear += $total;
    } elseif ($year === (int) $currentYear - 1) {
        $actualLastYear += $total;
    }
}
$actualYearOverYear = $actualLastYear > 0 ? ($actualThisYear - $actualLastYear) / $actualLastYear * 100 : null;

?>
<?php page_toolbar_start(translate('stats', $i18n), $statsSubtitle !== '' ? trim($statsSubtitle, '()') : translate('general_statistics', $i18n)); ?>
    <?php if ($statsHousehold !== null) {
      $scopeQuery = $_GET;
      ?>
      <div class="segmented" role="group" aria-label="<?= translate('household', $i18n) ?>">
        <?php foreach (['mine' => translate('scope_mine', $i18n), 'household' => translate('scope_household', $i18n)] as $scopeValue => $scopeLabel) {
          $scopeQuery['scope'] = $scopeValue;
          ?>
          <a class="segmented-option<?= $statsScope === $scopeValue ? ' is-selected' : '' ?>" aria-current="<?= $statsScope === $scopeValue ? 'true' : 'false' ?>"
            href="stats.php?<?= htmlspecialchars(http_build_query($scopeQuery)) ?>"><?= htmlspecialchars($scopeLabel) ?></a>
        <?php } ?>
      </div>
    <?php } ?>
    <?php
    // Gross / My cost (issue #34), offered once something is split or reimbursed.
    $hasCostAdjustments = $costSharesById !== [] || $statsCostMode === 'net';
    foreach ($subscriptions as $costRow) {
      if ((float) ($costRow['reimbursed_percent'] ?? 0) > 0) {
        $hasCostAdjustments = true;
        break;
      }
    }
    if ($hasCostAdjustments) {
      $costQuery = $_GET;
      ?>
      <div class="segmented" role="group" aria-label="<?= translate('cost_view', $i18n) ?>">
        <?php foreach (['gross' => translate('cost_gross', $i18n), 'net' => translate('cost_mine', $i18n)] as $costValue => $costLabel) {
          $costQuery['cost'] = $costValue;
          ?>
          <a class="segmented-option<?= $statsCostMode === $costValue ? ' is-selected' : '' ?>" aria-current="<?= $statsCostMode === $costValue ? 'true' : 'false' ?>"
            href="stats.php?<?= htmlspecialchars(http_build_query($costQuery)) ?>"><?= htmlspecialchars($costLabel) ?></a>
        <?php } ?>
      </div>
    <?php } ?>
    <div class="segmented stats-period" role="group" aria-label="<?= translate('period', $i18n) ?>">
      <?php
      $periodOptions = ['normalized' => 'period_normalized', 'calendar' => 'period_calendar_month', 'pay' => 'period_pay_period', 'custom' => 'period_custom'];
      foreach ($periodOptions as $periodValue => $periodLabelKey) {
        $isCurrentPeriod = $statsPeriod['period'] === $periodValue;
        if ($periodValue === 'custom') {
          ?>
          <button type="button" class="segmented-option<?= $isCurrentPeriod ? ' is-selected' : '' ?>" aria-pressed="<?= $isCurrentPeriod ? 'true' : 'false' ?>"
            aria-controls="stats-custom-range" aria-expanded="<?= $statsPeriod['period'] === 'custom' || $statsPeriod['error'] ? 'true' : 'false' ?>"
            onClick="toggleCustomRange(this)"><?= translate($periodLabelKey, $i18n) ?></button>
          <?php
          continue;
        }
        $periodHref = $periodQuery(['period' => $periodValue === 'normalized' ? null : $periodValue, 'from' => null, 'to' => null]);
        ?>
        <a class="segmented-option<?= $isCurrentPeriod ? ' is-selected' : '' ?>" aria-current="<?= $isCurrentPeriod ? 'true' : 'false' ?>"
          href="<?= htmlspecialchars($periodHref) ?>"><?= translate($periodLabelKey, $i18n) ?></a>
      <?php } ?>
    </div>
    <div class="filtermenu">
      <button type="button" class="btn btn-icon" id="filtermenu-button" title="<?= translate("filter", $i18n) ?>" aria-label="<?= translate("filter", $i18n) ?>">
        <i class="fa-solid fa-filter"></i>
      </button>
      <div class="filtermenu-content">
        <?php
        if (count($members) > 1) {
          ?>
          <div class="filtermenu-submenu">
            <div class="filter-title" onClick="toggleSubMenu('member')"><?= translate("member", $i18n) ?></div>
            <div class="filtermenu-submenu-content" id="filter-member">
              <?php
              foreach ($members as $member) {
                $isSelected = isset($_GET['member']) && in_array($member['id'], array_map('intval', explode(',', $_GET['member'])));
                if (($menuMemberCounts[$member['id']] ?? 0) == 0 && !$isSelected) {
                  continue;
                }
                $selectedClass = $isSelected ? 'selected' : '';
                ?>
                <div class="filter-item <?= $selectedClass ?>" data-memberid="<?= $member['id'] ?>"><?= $member['name'] ?>
                </div>
                <?php
              }
              ?>
            </div>
          </div>
          <?php
        }
        ?>
        <?php
        if (count($categories) > 1) {
          // sort categories by order
          usort($categories, function ($a, $b) {
            return $a['order'] - $b['order'];
          });
          ?>
          <div class="filtermenu-submenu">
            <div class="filter-title" onClick="toggleSubMenu('category')"><?= translate("category", $i18n) ?></div>
            <div class="filtermenu-submenu-content" id="filter-category">
              <?php
              // A category still carrying its untouched default name is
              // recognised against the account's own creation-language
              // default, not a literal English string - #1216 gives every
              // language its own default, so an account created in German
              // never had an English name to match here.
              $noCategoryDefaultName = default_no_category_name($userData['language'] ?? 'en');
              foreach ($categories as $category) {
                $isSelected = isset($_GET['category']) && in_array($category['id'], array_map('intval', explode(',', $_GET['category'])));
                if (($menuCategoryCounts[$category['id']] ?? 0) == 0 && !$isSelected) {
                  continue;
                }
                $categoryName = $category['name'] === $noCategoryDefaultName ? translate("no_category", $i18n) : $category['name'];
                $selectedClass = $isSelected ? 'selected' : '';
                ?>
                <div class="filter-item <?= $selectedClass ?>" data-categoryid="<?= $category['id'] ?>">
                  <?= $categoryName ?>
                </div>
                <?php
              }
              ?>
            </div>
          </div>
          <?php
        }
        ?>
        <?php
        if (count($paymentMethods) > 1) {

          usort($paymentMethods, function ($a, $b) {
            return $a['order'] <=> $b['order'];
          });
          ?>
          <div class="filtermenu-submenu">
            <div class="filter-title" onClick="toggleSubMenu('payment')"><?= translate("payment_method", $i18n) ?></div>
            <div class="filtermenu-submenu-content" id="filter-payment">
              <?php
              foreach ($paymentMethods as $payment) {
                $isSelected = isset($_GET['payment']) && in_array($payment['id'], array_map('intval', explode(',', $_GET['payment'])));
                if (($menuPaymentCounts[$payment['id']] ?? 0) == 0 && !$isSelected) {
                  continue;
                }
                $selectedClass = $isSelected ? 'selected' : '';
                ?>
                <div class="filter-item <?= $selectedClass ?>" data-paymentid="<?= $payment['id'] ?>">
                  <?= $payment['name'] ?>
                </div>
                <?php
              }
              ?>
            </div>
          </div>
          <?php
        }
        ?>
        <?php
        if (isset($_GET['member']) || isset($_GET['category']) || isset($_GET['payment'])) {
          ?>
          <div class="filtermenu-submenu">
            <div class="filter-title filter-clear" onClick="clearFilters()">
              <i class="fa-solid fa-times-circle"></i> <?= translate("clear", $i18n) ?>
            </div>
          </div>
          <?php
        }
        ?>
      </div>
    </div>
<?php page_toolbar_end(); ?>

<section class="contain">
  <form class="stats-custom-range<?= $statsPeriod['period'] === 'custom' || $statsPeriod['error'] ? '' : ' hide' ?>" id="stats-custom-range" method="get" action="stats.php">
    <?php foreach ($_GET as $key => $value) {
      if (in_array($key, ['period', 'from', 'to'], true) || !is_string($value)) {
        continue;
      } ?>
      <input type="hidden" name="<?= htmlspecialchars($key) ?>" value="<?= htmlspecialchars($value) ?>">
    <?php } ?>
    <input type="hidden" name="period" value="custom">
    <label for="stats-range-from"><?= translate('from', $i18n) ?></label>
    <input type="date" id="stats-range-from" name="from" required
      value="<?= htmlspecialchars($statsPeriod['period'] === 'custom' ? $statsPeriod['start']->format('Y-m-d') : (new DateTime('first day of this month'))->format('Y-m-d')) ?>">
    <label for="stats-range-to"><?= translate('to', $i18n) ?></label>
    <input type="date" id="stats-range-to" name="to" required
      value="<?= htmlspecialchars($statsPeriod['period'] === 'custom' ? $statsPeriod['end']->format('Y-m-d') : (new DateTime('last day of this month'))->format('Y-m-d')) ?>">
    <button type="submit" class="btn btn-small"><?= translate('apply', $i18n) ?></button>
    <?php if ($statsPeriod['error']) { ?>
      <p class="form-hint is-danger"><?= translate('invalid_date_range', $i18n) ?></p>
    <?php } ?>
  </form>
  <?php
  if ($showCantConverErrorMessage) {
    ?>
    <div class="error-box">
      <div class="error-message">
        <i class="fa-solid fa-exclamation-circle"></i>
        <?= translate('cant_convert_currency', $i18n) ?>
      </div>
    </div>
    <?php
  }
  ?>
  <?php
  // Graph datasets for the split views
  // Sub-categories roll up into their parent; filtered to one parent, the
  // split shows its sub-categories instead (drill-down). A parent's slice
  // links to that view.
  $categoryFilterIds = isset($_GET['category']) && $_GET['category'] !== '' ? array_map('intval', explode(',', $_GET['category'])) : [];
  $categoryDrillDown = count($categoryFilterIds) === 1 && isset($categoriesById[$categoryFilterIds[0]])
    && (int) ($categoriesById[$categoryFilterIds[0]]['parent_id'] ?? 0) === 0;
  $categoryHasChildren = [];
  foreach ($categoriesById as $categoryRow) {
    if ((int) ($categoryRow['parent_id'] ?? 0) > 0) {
      $categoryHasChildren[(int) $categoryRow['parent_id']] = true;
    }
  }
  $splitCategoryCost = $categoryDrillDown ? ($categoryCost ?? []) : wallos_roll_up_categories($categoryCost ?? [], $categoriesById);
  $categoryDataPoints = [];
  foreach ($splitCategoryCost as $splitCategoryId => $category) {
    if ($category['cost'] != 0) {
      $point = [
        "label" => html_entity_decode($category['name']),
        "y" => $category["cost"],
      ];
      if (!$categoryDrillDown && isset($categoryHasChildren[(int) $splitCategoryId])) {
        $point['href'] = 'stats.php?' . http_build_query(array_merge($_GET, ['category' => (int) $splitCategoryId]));
      }
      $categoryDataPoints[] = $point;
    }
  }
  usort($categoryDataPoints, fn($a, $b) => $b['y'] <=> $a['y']);
  // A single slice says nothing, unless it is a parent that opens its sub-categories.
  $categorySplitWorthShowing = fn($points) => count($points) > 1 || (count($points) === 1 && !empty($points[0]['href']));
  $showCategoryCostGraph = $categorySplitWorthShowing($categoryDataPoints);

  $memberDataPoints = [];
  if (isset($memberCost)) {
    foreach ($memberCost as $member) {
      if ($member['cost'] != 0) {
        $memberDataPoints[] = [
          "label" => html_entity_decode($member['name']),
          "y" => $member["cost"],
        ];
      }
    }
  }
  usort($memberDataPoints, fn($a, $b) => $b['y'] <=> $a['y']);
  $showMemberCostGraph = count($memberDataPoints) > 1;

  $paymentMethodDataPoints = [];
  foreach ($paymentMethodCost as $paymentMethodId => $cost) {
    if ($cost > 0 && isset($paymentMethodsCount[$paymentMethodId])) {
      $paymentMethodDataPoints[] = [
        "label" => html_entity_decode($paymentMethodsCount[$paymentMethodId]['name']),
        "y" => round($cost, 2),
      ];
    }
  }
  usort($paymentMethodDataPoints, fn($a, $b) => $b['y'] <=> $a['y']);
  $showPaymentMethodsGraph = count($paymentMethodDataPoints) > 1;

  // A period other than "per month" splits what is charged in it instead.
  $splitSubHeader = translate('monthly_cost', $i18n);
  if ($statsPeriodActive) {
    $splitSubHeader = $statsPeriodLabel;
    $periodByCategory = $periodCharges['by_category'];
    if (!$categoryDrillDown) {
      $rolledUp = [];
      foreach ($periodByCategory as $periodCategoryId => $amount) {
        $rootId = wallos_category_root_id($categoriesById, $periodCategoryId);
        $rolledUp[$rootId] = ($rolledUp[$rootId] ?? 0) + $amount;
      }
      $periodByCategory = $rolledUp;
    }
    // Same points as wallos_period_split_points(), keeping the drill-down link on parents.
    $categoryDataPoints = [];
    foreach ($periodByCategory as $periodCategoryId => $amount) {
      if ($amount > 0 && isset($categoryCost[$periodCategoryId])) {
        $point = ['label' => html_entity_decode($categoryCost[$periodCategoryId]['name']), 'y' => round($amount, 2)];
        if (!$categoryDrillDown && isset($categoryHasChildren[(int) $periodCategoryId])) {
          $point['href'] = 'stats.php?' . http_build_query(array_merge($_GET, ['category' => (int) $periodCategoryId]));
        }
        $categoryDataPoints[] = $point;
      }
    }
    usort($categoryDataPoints, fn($a, $b) => $b['y'] <=> $a['y']);
    $memberDataPoints = wallos_period_split_points($periodCharges['by_member'], array_map(fn($row) => $row['name'], $memberCost ?? []));
    $paymentMethodDataPoints = wallos_period_split_points($periodCharges['by_payment'], array_map(fn($row) => $row['name'], $paymentMethodsCount ?? []));
    $showCategoryCostGraph = $categorySplitWorthShowing($categoryDataPoints);
    $showMemberCostGraph = count($memberDataPoints) > 1;
    $showPaymentMethodsGraph = count($paymentMethodDataPoints) > 1;
  }

  $showMonthlyStats = !isset($_GET['budget']) || $_GET['budget'] === 'monthly';
  $showPeriodStats = !isset($_GET['budget']) || $_GET['budget'] === 'period';

  $showAnyGraph = $showCategoryCostGraph || $showMemberCostGraph || $showPaymentMethodsGraph || $showTotalMonthlyCostGraph
    || ($showMonthlyStats && $showVsMonthlyBudgetGraph) || ($showPeriodStats && $showVsPeriodBudgetGraph)
    || $showProjectionGraph || $showLifetimeGraph || $showCycleGraph || $showCurrencyGraph || $showHistogramGraph || $showYearlyNewGraph
    || $showActualPaymentsGraph;

  $showTrendsSection = $showTotalMonthlyCostGraph || $showProjectionGraph || $monthOverMonthDelta !== null;
  $showBudgetSection = ($showMonthlyStats && (isset($monthlyBudgetUsed) || isset($monthlyBudgetLeft) || isset($monthlyOverBudgetAmount) || $showVsMonthlyBudgetGraph))
    || ($showPeriodStats && (isset($periodBudgetUsed) || isset($periodBudgetLeft) || isset($periodOverBudgetAmount) || $showVsPeriodBudgetGraph));
  $showSplitSection = $showMemberCostGraph || $showCategoryCostGraph || $showPaymentMethodsGraph || $showCycleGraph || $showCurrencyGraph || $showHistogramGraph;
  $showHistorySection = $totalLifetimeSpend > 0 || $oldestSubscription !== null || $averageSubscriptionAge !== null || $showLifetimeGraph || $showYearlyNewGraph
    || $showActualPaymentsGraph;
  ?>

  <?php if ($statsPeriodActive) {
    $periodTitleKey = ['calendar' => 'period_calendar_month', 'pay' => 'period_pay_period', 'custom' => 'period_custom'][$statsPeriod['period']];
    ?>
    <section class="stats-section">
      <h2><?= translate('charges_in_period', $i18n) ?> · <?= htmlspecialchars($statsPeriodLabel) ?></h2>
      <div class="statistics">
        <div class="statistic">
          <span><?= CurrencyFormatter::format($periodCharges['total'], $code) ?></span>
          <div class="title"><?= translate($periodTitleKey, $i18n) ?></div>
          <div class="period-range"><?= htmlspecialchars($statsPeriodLabel) ?></div>
        </div>
        <div class="statistic">
          <span><?= (int) $periodCharges['count'] ?></span>
          <div class="title"><?= translate('renewals', $i18n) ?></div>
        </div>
        <div class="statistic">
          <span><?= CurrencyFormatter::format($periodCharges['total'] / max(1, $statsPeriodDays), $code) ?></span>
          <div class="title"><?= translate('cost_per_day', $i18n) ?></div>
        </div>
      </div>
      <?php if ($statsPeriodIncludesPast) { ?>
        <p class="stats-note"><?= translate('period_past_is_estimated', $i18n) ?></p>
      <?php } ?>
      <?php if (empty($periodCharges['charges'])) { ?>
        <p class="stats-note"><?= translate('no_charges_in_period', $i18n) ?></p>
      <?php } else { ?>
        <div class="card period-charges">
          <div class="card-list">
            <?php
            foreach ($periodCharges['charges'] as $charge) {
              $chargeSubtitle = tally_format_date($charge['date'], 'long', $lang);
              if (!empty($charge['shared_owner_name'])) {
                $chargeSubtitle = sprintf(translate('shared_by', $i18n), $charge['shared_owner_name']) . ' · ' . $chargeSubtitle;
              }
              ?>
              <div class="list-row">
                <?= render_tile($charge['name'], empty($charge['logo']) ? '' : 'images/uploads/logos/' . $charge['logo'],
                  empty($charge['logo_variant']) ? null : 'images/uploads/logos/' . $charge['logo_variant'], $charge['logo_text_color']) ?>
                <span class="list-row-text">
                  <span class="list-row-title"><?= htmlspecialchars(htmlspecialchars_decode($charge['name'])) ?></span>
                  <span class="list-row-subtitle"><?= htmlspecialchars($chargeSubtitle) ?></span>
                </span>
                <span class="list-row-trailing">
                  <span class="list-row-value"><?= htmlspecialchars(CurrencyFormatter::format($charge['amount'], $code)) ?></span>
                </span>
              </div>
            <?php
            } ?>
          </div>
        </div>
      <?php } ?>
    </section>
  <?php } ?>

  <section class="stats-section">
    <h2><?= translate('overview', $i18n) ?></h2>
    <?php if ($statsCostMode === 'net') { ?>
      <p class="stats-note"><?= translate('cost_mine_note', $i18n) ?></p>
    <?php } elseif ($hasCostAdjustments) { ?>
      <p class="stats-note"><?= translate('cost_gross_note', $i18n) ?></p>
    <?php } ?>
    <div class="statistics">
      <div class="statistic">
        <span><?= $activeSubscriptions ?></span>
        <div class="title"><?= translate('active_subscriptions', $i18n) ?></div>
      </div>
      <div class="statistic">
        <span><?= CurrencyFormatter::format($headlineMonthlyCost, $code) ?></span>
        <div class="title"><?= translate($monthlyCostMode === 'cashflow' ? 'charges_this_month' : 'monthly_cost', $i18n) ?></div>
        <?php if ($excludedYearlyCost > 0) { ?>
          <div class="subtitle"><?= sprintf(translate('plus_yearly_in_longer_plans', $i18n), CurrencyFormatter::format($excludedYearlyCost, $code)) ?></div>
        <?php } elseif ($monthlyCostMode !== 'amortized') { ?>
          <div class="subtitle"><?= sprintf(translate('spread_evenly_amount', $i18n), CurrencyFormatter::format($totalCostPerMonth, $code)) ?></div>
        <?php } ?>
      </div>
      <div class="statistic">
        <span><?= CurrencyFormatter::format($totalCostPerYear, $code) ?></span>
        <div class="title"><?= translate('yearly_cost', $i18n) ?></div>
      </div>
      <?php
      if ($totalCostPerMonth > 0) {
        ?>
        <div class="statistic">
          <span><?= CurrencyFormatter::format($costPerDay, $code) ?></span>
          <div class="title"><?= translate('cost_per_day', $i18n) ?></div>
        </div>
        <?php
      }
      ?>
      <div class="statistic">
        <span><?= CurrencyFormatter::format($averageSubscriptionCost, $code) ?></span>
        <div class="title"><?= translate('average_monthly', $i18n) ?></div>
      </div>
      <div class="statistic short">
        <span><?= CurrencyFormatter::format($mostExpensiveSubscription['price'], $code) ?></span>
        <div class="title"><?= translate('most_expensive', $i18n) ?></div>
        <?php
        if (isset($mostExpensiveSubscription['logo']) && $mostExpensiveSubscription['logo'] != '') {
          $mostExpensiveLogoSrc = "images/uploads/logos/" . $mostExpensiveSubscription['logo'];
          $mostExpensiveLogoVariantSrc = !empty($mostExpensiveSubscription['logo_variant']) ? "images/uploads/logos/" . $mostExpensiveSubscription['logo_variant'] : null;
          ?>
          <div class="subtitle">
            <?= renderThemedLogoImg($mostExpensiveLogoSrc, $mostExpensiveLogoVariantSrc, $mostExpensiveSubscription['logo_text_color'] ?? null, '', 'alt="' . $mostExpensiveSubscription['name'] . '" title="' . $mostExpensiveSubscription['name'] . '"') ?>
          </div>
          <?php
        } else if (isset($mostExpensiveSubscription['name']) && $mostExpensiveSubscription['name'] != '') {
          ?>
          <div class="subtitle"><?= $mostExpensiveSubscription['name'] ?></div>
          <?php
        }
        ?>
      </div>
      <?php
      if ($cheapestSubscription !== null) {
        ?>
        <div class="statistic short">
          <span><?= CurrencyFormatter::format($cheapestSubscription['price'], $code) ?></span>
          <div class="title"><?= translate('cheapest_subscription', $i18n) ?></div>
          <?php
          if (!empty($cheapestSubscription['logo'])) {
            $cheapestLogoSrc = "images/uploads/logos/" . $cheapestSubscription['logo'];
            $cheapestLogoVariantSrc = !empty($cheapestSubscription['logo_variant']) ? "images/uploads/logos/" . $cheapestSubscription['logo_variant'] : null;
            ?>
            <div class="subtitle">
              <?= renderThemedLogoImg($cheapestLogoSrc, $cheapestLogoVariantSrc, $cheapestSubscription['logo_text_color'] ?? null, '', 'alt="' . $cheapestSubscription['name'] . '" title="' . $cheapestSubscription['name'] . '"') ?>
            </div>
            <?php
          } else if (!empty($cheapestSubscription['name'])) {
            ?>
            <div class="subtitle"><?= $cheapestSubscription['name'] ?></div>
            <?php
          }
          ?>
        </div>
        <?php
      }
      ?>
      <div class="statistic">
        <span><?= CurrencyFormatter::format($amountDueThisMonth, $code) ?></span>
        <div class="title"><?= translate('amount_due', $i18n) ?></div>
      </div>
      <?php
      if ($manualRenewalsCount > 0) {
        ?>
        <div class="statistic">
          <span><?= $manualRenewalsCount ?></span>
          <div class="title"><?= translate('manual_renewals', $i18n) ?></div>
        </div>
        <?php
      }
      ?>
    </div>
  </section>

  <?php
  if ($showTrendsSection) {
    ?>
    <section class="stats-section">
      <h2><?= translate('trends_and_forecast', $i18n) ?></h2>
      <?php
      if ($monthOverMonthDelta !== null || ($showProjectionGraph && $heaviestMonth !== null) || $monthsOverBudget !== null) {
        ?>
        <div class="statistics">
          <?php
          if ($monthOverMonthDelta !== null) {
            $momSign = $monthOverMonthDelta >= 0 ? '+' : '-';
            ?>
            <div class="statistic short">
              <span><?= $momSign ?><?= CurrencyFormatter::format(abs($monthOverMonthDelta), $code) ?></span>
              <div class="title"><?= translate('vs_last_month', $i18n) ?></div>
              <?php
              if ($monthOverMonthPercentage !== null) {
                ?>
                <div class="subtitle"><?= $momSign ?><?= number_format(abs($monthOverMonthPercentage), 1) ?>%</div>
                <?php
              }
              ?>
            </div>
            <?php
          }
          if ($showProjectionGraph && $heaviestMonth !== null) {
            ?>
            <div class="statistic short">
              <span><?= CurrencyFormatter::format($heaviestMonth['total'], $code) ?></span>
              <div class="title"><?= translate('heaviest_month', $i18n) ?></div>
              <div class="subtitle capitalize"><?= $heaviestMonth['label'] ?></div>
            </div>
            <?php
          }
          if ($monthsOverBudget !== null) {
            ?>
            <div class="statistic">
              <span><?= $monthsOverBudget ?></span>
              <div class="title"><?= translate('months_over_budget', $i18n) ?></div>
            </div>
            <?php
          }
          ?>
        </div>
        <?php
      }
      if ($showTotalMonthlyCostGraph || $showProjectionGraph) {
        ?>
        <div class="graphs">
          <?php
          if ($showTotalMonthlyCostGraph) {
            ?>
            <section class="graph x2">
              <header>
                <?= translate('total_cost_trend', $i18n) ?>
                <div class="sub-header">(<?= translate('monthly_cost', $i18n) ?>)</div>
              </header>
              <div id="totalMonthlyCostChart" style="width: 100%;"></div>
            </section>
            <?php
          }
          if ($showProjectionGraph) {
            ?>
            <section class="graph x2">
              <header>
                <?= translate('projected_cost', $i18n) ?>
              </header>
              <div id="projectionChart" style="width: 100%;"></div>
            </section>
            <?php
          }
          ?>
        </div>
        <?php
      }
      ?>
    </section>
    <?php
  }

  if ($showBudgetSection) {
    ?>
    <section class="stats-section">
      <h2><?= translate('budget', $i18n) ?></h2>
      <div class="statistics">
        <?php
        if ($showMonthlyStats && isset($monthlyBudgetUsed)) {
          ?>
          <div class="statistic">
            <span><?= number_format($monthlyBudgetUsed, 2) ?>%</span>
            <div class="title"><?= translate('monthly_budget', $i18n) ?> - <?= translate('percentage_budget_used', $i18n) ?></div>
          </div>
          <div class="statistic">
            <span><?= CurrencyFormatter::format($monthlyBudgetLeft, $code) ?></span>
            <div class="title"><?= translate('monthly_budget', $i18n) ?> - <?= translate('budget_remaining', $i18n) ?></div>
          </div>
          <?php
          if (isset($monthlyOverBudgetAmount)) {
            ?>
            <div class="statistic">
              <span><?= CurrencyFormatter::format($monthlyOverBudgetAmount, $code) ?></span>
              <div class="title"><?= translate('monthly_budget', $i18n) ?> - <?= translate('amount_over_budget', $i18n) ?></div>
            </div>
            <?php
          }
        }

        if ($showPeriodStats && isset($periodBudgetUsed)) {
          $periodRangeLabel = htmlspecialchars($budgetPeriodLabel, ENT_QUOTES, 'UTF-8');
          ?>
          <div class="statistic">
            <span><?= CurrencyFormatter::format($amountNeededThisPeriod, $code) ?></span>
            <div class="title"><?= translate('amount_needed_this_period', $i18n) ?></div>
            <div class="period-range"><?= $periodRangeLabel ?></div>
          </div>
          <div class="statistic">
            <span><?= number_format($periodBudgetUsed, 2) ?>%</span>
            <div class="title"><?= translate('period_budget', $i18n) ?> - <?= translate('percentage_budget_used', $i18n) ?></div>
            <div class="period-range"><?= $periodRangeLabel ?></div>
          </div>
          <div class="statistic">
            <span><?= CurrencyFormatter::format($periodBudgetLeft, $code) ?></span>
            <div class="title"><?= translate('period_budget', $i18n) ?> - <?= translate('budget_remaining', $i18n) ?></div>
            <div class="period-range"><?= $periodRangeLabel ?></div>
          </div>
          <?php
          if (isset($periodOverBudgetAmount)) {
            ?>
            <div class="statistic">
              <span><?= CurrencyFormatter::format($periodOverBudgetAmount, $code) ?></span>
              <div class="title"><?= translate('period_budget', $i18n) ?> - <?= translate('amount_over_budget', $i18n) ?></div>
              <div class="period-range"><?= $periodRangeLabel ?></div>
            </div>
            <?php
          }
        }
        ?>
      </div>
      <?php
      if (($showMonthlyStats && $showVsMonthlyBudgetGraph) || ($showPeriodStats && $showVsPeriodBudgetGraph)) {
        ?>
        <div class="graphs">
          <?php if ($showMonthlyStats && $showVsMonthlyBudgetGraph) { ?>
            <section class="graph">
              <header>
                <?= translate('cost_vs_monthly_budget', $i18n) ?> (<?= CurrencyFormatter::format($monthlyBudget, $code) ?>)
                <div class="sub-header">(<?= translate('monthly_cost', $i18n) ?>)</div>
              </header>
              <div id="monthlyBudgetVsCostChart" style="width: 100%;"></div>
            </section>
          <?php } ?>
          <?php if ($showPeriodStats && $showVsPeriodBudgetGraph) { ?>
            <section class="graph">
              <header>
                <?= translate('cost_vs_period_budget', $i18n) ?> (<?= CurrencyFormatter::format($periodBudget, $code) ?>)
                <div class="sub-header">(<?= translate('amount_needed_this_period', $i18n) ?>)</div>
              </header>
              <div id="periodBudgetVsCostChart" style="width: 100%;"></div>
            </section>
          <?php } ?>
        </div>
        <?php
      }
      ?>
    </section>
    <?php
  }

  // Who owes what: each member's monthly share of what someone else pays.
  if (!empty($memberOwed)) {
    ?>
    <section class="stats-section">
      <h2><?= translate('who_owes_what', $i18n) ?></h2>
      <div class="card">
        <div class="card-list">
          <?php foreach ($memberOwed as $owingMemberId => $owedByPayer) {
            foreach ($owedByPayer as $owedPayerId => $owedAmount) {
              $owingName = $members[$owingMemberId]['name'] ?? '';
              $owedPayerName = $members[$owedPayerId]['name'] ?? '';
              ?>
              <div class="list-row">
                <?= render_tile($owingName, '', null, null) ?>
                <span class="list-row-text">
                  <span class="list-row-title"><?= htmlspecialchars($owingName) ?></span>
                  <span class="list-row-subtitle"><?= htmlspecialchars(sprintf(translate('owes_payer', $i18n), $owedPayerName)) ?></span>
                </span>
                <span class="list-row-trailing">
                  <span class="list-row-value"><?= htmlspecialchars(CurrencyFormatter::format($owedAmount, $code)) ?><?= translate('per_month_short', $i18n) ?></span>
                </span>
              </div>
            <?php }
          } ?>
        </div>
      </div>
      <p class="stats-note"><?= translate('who_owes_what_info', $i18n) ?></p>
    </section>
    <?php
  }

  if ($showSplitSection) {
    ?>
    <section class="stats-section">
      <h2><?= translate('split_views', $i18n) ?></h2>
      <div class="graphs">
        <?php
        if ($showMemberCostGraph) {
          ?>
          <section class="graph">
            <header>
              <?= translate('household_split', $i18n) ?>
              <div class="sub-header">(<?= htmlspecialchars($splitSubHeader) ?>)</div>
            </header>
            <div id="memberSplitChart" style="width: 100%;"></div>
          </section>
          <?php
        }
        if ($showCategoryCostGraph) {
          ?>
          <section class="graph">
            <header>
              <?= translate('category_split', $i18n) ?>
              <div class="sub-header">(<?= htmlspecialchars($splitSubHeader) ?>)</div>
            </header>
            <div id="categorySplitChart" style="width: 100%;"></div>
          </section>
          <?php
        }
        if ($showPaymentMethodsGraph) {
          ?>
          <section class="graph">
            <header>
              <?= translate('payment_method_split', $i18n) ?>
              <div class="sub-header">(<?= htmlspecialchars($splitSubHeader) ?>)</div>
            </header>
            <div id="paymentMethidSplitChart" style="width: 100%;"></div>
          </section>
          <?php
        }
        if ($showCycleGraph) {
          ?>
          <section class="graph">
            <header>
              <?= translate('billing_cycle_split', $i18n) ?>
              <div class="sub-header">(<?= translate('monthly_cost', $i18n) ?>)</div>
            </header>
            <div id="cycleSplitChart" style="width: 100%;"></div>
          </section>
          <?php
        }
        if ($showCurrencyGraph) {
          ?>
          <section class="graph">
            <header>
              <?= translate('currency_split', $i18n) ?>
              <div class="sub-header">(<?= translate('monthly_cost', $i18n) ?>)</div>
            </header>
            <div id="currencySplitChart" style="width: 100%;"></div>
          </section>
          <?php
        }
        if ($showHistogramGraph) {
          ?>
          <section class="graph">
            <header>
              <?= translate('price_distribution', $i18n) ?>
              <div class="sub-header">(<?= translate('monthly_cost', $i18n) ?>, <?= $code ?>)</div>
            </header>
            <div id="priceHistogramChart" style="width: 100%;"></div>
          </section>
          <?php
        }
        ?>
      </div>
    </section>
    <?php
  }

  if ($showHistorySection) {
    ?>
    <section class="stats-section">
      <h2><?= translate('history_and_lifetime', $i18n) ?></h2>
      <?php
      if ($totalLifetimeSpend > 0 || $oldestSubscription !== null || $averageSubscriptionAge !== null || $showActualPaymentsGraph) {
        ?>
        <div class="statistics">
          <?php
          if ($totalLifetimeSpend > 0) {
            ?>
            <div class="statistic">
              <span><?= CurrencyFormatter::format($totalLifetimeSpend, $code) ?></span>
              <div class="title"><?= translate('all_time_spend', $i18n) ?></div>
            </div>
            <?php
          }
          if ($oldestSubscription !== null) {
            ?>
            <div class="statistic short">
              <span><?= number_format($oldestSubscription['years'], 1) ?></span>
              <div class="title"><?= translate('oldest_subscription', $i18n) ?></div>
              <?php
              if (!empty($oldestSubscription['logo'])) {
                $oldestLogoSrc = "images/uploads/logos/" . $oldestSubscription['logo'];
                $oldestLogoVariantSrc = !empty($oldestSubscription['logo_variant']) ? "images/uploads/logos/" . $oldestSubscription['logo_variant'] : null;
                ?>
                <div class="subtitle">
                  <?= renderThemedLogoImg($oldestLogoSrc, $oldestLogoVariantSrc, $oldestSubscription['logo_text_color'] ?? null, '', 'alt="' . $oldestSubscription['name'] . '" title="' . $oldestSubscription['name'] . '"') ?>
                </div>
                <?php
              } else if (!empty($oldestSubscription['name'])) {
                ?>
                <div class="subtitle"><?= $oldestSubscription['name'] ?></div>
                <?php
              }
              ?>
            </div>
            <?php
          }
          if ($averageSubscriptionAge !== null) {
            ?>
            <div class="statistic">
              <span><?= number_format($averageSubscriptionAge, 1) ?></span>
              <div class="title"><?= translate('average_subscription_age', $i18n) ?></div>
            </div>
            <?php
          }
          if ($showActualPaymentsGraph) {
            ?>
            <div class="statistic">
              <span><?= CurrencyFormatter::format($actualThisYear, $code) ?></span>
              <div class="title"><?= translate('actually_paid_this_year', $i18n) ?></div>
              <?php if ($actualYearOverYear !== null) { ?>
                <div class="subtitle"><?= sprintf(translate('vs_same_period_last_year', $i18n), ($actualYearOverYear >= 0 ? '+' : '') . number_format($actualYearOverYear, 0) . '%') ?></div>
              <?php } ?>
            </div>
            <?php
          }
          ?>
        </div>
        <?php
      }
      if ($showLifetimeGraph || $showYearlyNewGraph || $showActualPaymentsGraph) {
        ?>
        <div class="graphs">
          <?php
          if ($showActualPaymentsGraph) {
            ?>
            <section class="graph">
              <header>
                <?= translate('actual_payments', $i18n) ?>
                <div class="sub-header"><?= translate('from_payment_history', $i18n) ?></div>
              </header>
              <div id="actualPaymentsChart" style="width: 100%;"></div>
            </section>
            <?php
          }
          if ($showYearlyNewGraph) {
            ?>
            <section class="graph">
              <header>
                <?= translate('new_subscriptions_per_year', $i18n) ?>
              </header>
              <div id="newPerYearChart" style="width: 100%;"></div>
            </section>
            <?php
          }
          if ($showLifetimeGraph) {
            ?>
            <section class="graph">
              <header>
                <?= translate('lifetime_spend', $i18n) ?>
                <div class="sub-header"><?= translate('estimated_from_current_prices', $i18n) ?></div>
              </header>
              <div id="lifetimeSpendChart" style="width: 100%;"></div>
            </section>
            <?php
          }
          ?>
        </div>
        <?php
      }
      ?>
    </section>
    <?php
  }

  if ($upcomingCancellationsCount > 0) {
    ?>
    <section class="stats-section">
      <h2><?= translate('upcoming_cancellations', $i18n) ?></h2>
      <div class="statistics">
        <div class="statistic">
          <span><?= $upcomingCancellationsCount ?></span>
          <div class="title"><?= translate('subscriptions', $i18n) ?></div>
        </div>
        <?php
        if ($potentialMonthlySavings > 0) {
          ?>
          <div class="statistic">
            <span><?= CurrencyFormatter::format($potentialMonthlySavings, $code) ?></span>
            <div class="title"><?= translate('potential_monthly_savings', $i18n) ?></div>
          </div>
          <div class="statistic">
            <span><?= CurrencyFormatter::format($potentialMonthlySavings * 12, $code) ?></span>
            <div class="title"><?= translate('potential_yearly_savings', $i18n) ?></div>
          </div>
          <?php
        }
        ?>
      </div>
    </section>
    <?php
  }

  if (!empty($priceChangeTotals['count'])) {
    ?>
    <section class="stats-section">
      <h2><?= translate('plan_changes', $i18n) ?></h2>
      <div class="statistics">
        <?php if ($priceChangeTotals['downgrade_monthly'] > 0) { ?>
          <div class="statistic">
            <span><?= CurrencyFormatter::format($priceChangeTotals['downgrade_monthly'], $code) ?></span>
            <div class="title"><?= translate('saved_by_downgrades', $i18n) ?></div>
            <?php if ($priceChangeTotals['downgrade_accrued'] >= 0.005) { ?>
              <div class="subtitle"><?= sprintf(translate('saved_so_far', $i18n), CurrencyFormatter::format($priceChangeTotals['downgrade_accrued'], $code)) ?></div>
            <?php } ?>
          </div>
        <?php } ?>
        <?php if ($priceChangeTotals['upgrade_monthly'] > 0) { ?>
          <div class="statistic">
            <span><?= CurrencyFormatter::format($priceChangeTotals['upgrade_monthly'], $code) ?></span>
            <div class="title"><?= translate('extra_from_upgrades', $i18n) ?></div>
            <?php if ($priceChangeTotals['upgrade_accrued'] >= 0.005) { ?>
              <div class="subtitle"><?= sprintf(translate('spent_so_far', $i18n), CurrencyFormatter::format($priceChangeTotals['upgrade_accrued'], $code)) ?></div>
            <?php } ?>
          </div>
        <?php } ?>
        <?php if (abs($priceChangeTotals['provider_monthly']) >= 0.005) {
          $providerSign = $priceChangeTotals['provider_monthly'] >= 0 ? '+' : '-';
          ?>
          <div class="statistic">
            <span><?= $providerSign ?><?= CurrencyFormatter::format(abs($priceChangeTotals['provider_monthly']), $code) ?></span>
            <div class="title"><?= translate('provider_price_changes', $i18n) ?></div>
            <?php if (abs($priceChangeTotals['provider_accrued']) >= 0.005) { ?>
              <div class="subtitle"><?= sprintf(translate('since_changes_total', $i18n), ($priceChangeTotals['provider_accrued'] >= 0 ? '+' : '-') . CurrencyFormatter::format(abs($priceChangeTotals['provider_accrued']), $code)) ?></div>
            <?php } ?>
          </div>
        <?php } ?>
      </div>
      <p class="stats-note"><?= translate('plan_changes_info', $i18n) ?></p>
    </section>
    <?php
  }

  if ($inactiveSubscriptions > 0 || ($planDowngradeSavingsPerMonth ?? 0) > 0) {
    ?>
    <section class="stats-section">
      <h2><?= translate('your_savings', $i18n) ?></h2>
      <div class="statistics">
        <?php if ($inactiveSubscriptions > 0) { ?>
          <div class="statistic">
            <span><?= $inactiveSubscriptions ?></span>
            <div class="title"><?= translate('inactive_subscriptions', $i18n) ?></div>
          </div>
        <?php } ?>
        <?php if (($planDowngradeSavingsPerMonth ?? 0) > 0) { ?>
          <div class="statistic">
            <span><?= CurrencyFormatter::format($planDowngradeSavingsPerMonth, $code) ?></span>
            <div class="title"><?= translate('saved_by_downgrades', $i18n) ?></div>
          </div>
        <?php } ?>
        <?php
        if ($totalSavingsPerMonth > 0) {
          ?>
          <div class="statistic">
            <span><?= CurrencyFormatter::format($totalSavingsPerMonth, $code) ?></span>
            <div class="title"><?= translate('monthly_savings', $i18n) ?></div>
          </div>
          <div class="statistic">
            <span><?= CurrencyFormatter::format($totalSavingsPerMonth * 12, $code) ?></span>
            <div class="title"><?= translate('yearly_savings', $i18n) ?></div>
          </div>
          <?php
        }
        ?>
      </div>
    </section>
    <?php
  }
  ?>

</section>
<?php
if ($showAnyGraph) {
  ?>
  <script src="scripts/libs/apexcharts.min.js"></script>
  <script type="text/javascript">
    window.mainCurrencyDecimals = <?= json_encode($mainCurrencyDecimals) ?>;
    window.mainCurrencySymbol = <?= json_encode($mainCurrencySymbol, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    window.onload = function () {
      loadLineGraph("totalMonthlyCostChart", <?php echo json_encode($totalMonthlyCostDataPoints, JSON_NUMERIC_CHECK); ?>, "<?= $code ?>", <?= $showTotalMonthlyCostGraph ? 1 : 0 ?>);
      loadBarGraph("projectionChart", <?php echo json_encode($projectionDataPoints, JSON_NUMERIC_CHECK); ?>, "<?= $code ?>", <?= $showProjectionGraph ? 1 : 0 ?>, <?= isset($budget) && $budget > 0 ? $budget : 'null' ?>);
      loadGraph("categorySplitChart", <?php echo json_encode($categoryDataPoints, JSON_NUMERIC_CHECK); ?>, "<?= $code ?>", <?= $showCategoryCostGraph ? 1 : 0 ?>);
      loadGraph("memberSplitChart", <?php echo json_encode($memberDataPoints, JSON_NUMERIC_CHECK); ?>, "<?= $code ?>", <?= $showMemberCostGraph ? 1 : 0 ?>);
      loadGraph("paymentMethidSplitChart", <?php echo json_encode($paymentMethodDataPoints, JSON_NUMERIC_CHECK); ?>, "<?= $code ?>", <?= $showPaymentMethodsGraph ? 1 : 0 ?>);
      <?php if ($showMonthlyStats && $showVsMonthlyBudgetGraph) { ?>
      loadGraph("monthlyBudgetVsCostChart", <?php echo json_encode($vsMonthlyBudgetDataPoints, JSON_NUMERIC_CHECK); ?>, "<?= $code ?>", 1);
      <?php } ?>
      <?php if ($showPeriodStats && $showVsPeriodBudgetGraph) { ?>
      loadGraph("periodBudgetVsCostChart", <?php echo json_encode($vsPeriodBudgetDataPoints, JSON_NUMERIC_CHECK); ?>, "<?= $code ?>", 1);
      <?php } ?>
      loadGraph("cycleSplitChart", <?php echo json_encode($cycleDataPoints, JSON_NUMERIC_CHECK); ?>, "<?= $code ?>", <?= $showCycleGraph ? 1 : 0 ?>);
      loadGraph("currencySplitChart", <?php echo json_encode($currencyDataPoints, JSON_NUMERIC_CHECK); ?>, "<?= $code ?>", <?= $showCurrencyGraph ? 1 : 0 ?>);
      loadBarGraph("priceHistogramChart", <?php echo json_encode($histogramDataPoints, JSON_NUMERIC_CHECK); ?>, "", <?= $showHistogramGraph ? 1 : 0 ?>, null);
      loadBarGraph("newPerYearChart", <?php echo json_encode($newPerYearDataPoints, JSON_NUMERIC_CHECK); ?>, "", <?= $showYearlyNewGraph ? 1 : 0 ?>, null);
      loadBarGraph("actualPaymentsChart", <?php echo json_encode($actualPaymentsDataPoints, JSON_NUMERIC_CHECK); ?>, "<?= $code ?>", <?= $showActualPaymentsGraph ? 1 : 0 ?>, null);
      loadHorizontalBarGraph("lifetimeSpendChart", <?php echo json_encode($lifetimeDataPoints, JSON_NUMERIC_CHECK); ?>, "<?= $code ?>", <?= $showLifetimeGraph ? 1 : 0 ?>);
    }
  </script>
  <?php
}
?>
<script src="scripts/stats.js?<?= $version ?>"></script>
<?php
require_once 'includes/footer.php';
?>