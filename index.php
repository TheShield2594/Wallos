<?php

require_once 'includes/header.php';
require_once 'includes/getdbkeys.php';
require_once 'includes/logo_theme_variant.php';
require_once 'includes/upcoming_payments.php';
require_once 'includes/upcoming_cancellations.php';
require_once 'includes/ui_helpers.php';
require_once 'includes/payment_ledger.php';
require_once 'includes/category_spend.php';
require_once 'includes/households.php';

// Get the first name of the user
$stmt = $db->prepare("SELECT username, firstname FROM user WHERE id = :userId");
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
$user = $result->fetchArray(SQLITE3_ASSOC);
$first_name = $user['firstname'] ?? $user['username'] ?? '';

// Fetch the enabled subscriptions up for payment using the user's dashboard setting.
$upcomingSubscriptions = get_upcoming_payments(
    $db,
    $userId,
    $settings['upcoming_payments_limit'] ?? 3
);

// Upcoming payments of subscriptions other household members share, with
// their owner's name, in the same list.
$upcomingLimit = normalize_upcoming_payments_limit($settings['upcoming_payments_limit'] ?? 3);
$todayString = (new DateTime('today'))->format('Y-m-d');
foreach (wallos_shared_subscriptions($db, $userId, true) as $sharedRow) {
    if ((int) $sharedRow['cycle'] === 5 || $sharedRow['next_payment'] < $todayString) {
        continue;
    }
    $sharedRow['price'] = wallos_price_on($sharedRow, $sharedRow['next_payment']);
    $upcomingSubscriptions[] = $sharedRow;
}
usort($upcomingSubscriptions, fn($a, $b) => strcmp($a['next_payment'], $b['next_payment']));
$upcomingSubscriptions = array_slice($upcomingSubscriptions, 0, $upcomingLimit);

// Fetch enabled subscriptions with manual renewal that are overdue
$stmt = $db->prepare("SELECT id, logo, logo_text_color, logo_variant, name, price, currency_id, next_payment, inactive, auto_renew FROM subscriptions WHERE user_id = :userId AND next_payment < date('now') AND auto_renew = 0 AND inactive = 0 AND cycle != 5 ORDER BY next_payment ASC");
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
$overdueSubscriptions = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $overdueSubscriptions[] = $row;
}
$hasOverdueSubscriptions = !empty($overdueSubscriptions);

// Cycles the renewal cron moved past that still need the user: amounts to
// enter for variable bills, and unconfirmed renewals when the user asked to
// confirm every one.
$paymentsToReview = wallos_ledger_open_items($db, $userId, !empty($settings['require_payment_confirmation']));

// Fetch the subscriptions whose cancellation reminder is still ahead (shared with
// the statistics page, so both stay in step).
// The card covers this calendar month and the next; "View all" opens the
// whole list sorted by cancel-by date.
$allUpcomingCancellations = get_upcoming_cancellations($db, $userId);
$cancelWindowEnd = wallos_cancel_window_end((new DateTime('today'))->format('Y-m-d'));
$upcomingCancellations = array_values(array_filter($allUpcomingCancellations, fn($row) => $row['cancellation_date'] <= $cancelWindowEnd));
$laterCancellationsCount = count($allUpcomingCancellations) - count($upcomingCancellations);
$hasUpcomingCancellations = !empty($allUpcomingCancellations);

require_once 'includes/stats_calculations.php';

// Get AI Recommendations for user
$stmt = $db->prepare("SELECT * FROM ai_recommendations WHERE user_id = :userId");
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
$aiRecommendations = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $aiRecommendations[] = $row;
}

// What cancelling the subscriptions on the card would stop costing per month.
$upcomingCancellationsSaving = get_upcoming_cancellations_monthly_value($upcomingCancellations, $db, $userId);

$mainCurrencyCode = $currencies[$userData['main_currency']]['code'];
$money = function ($amount) use ($mainCurrencyCode, $currencies) {
    return formatPrice($amount, $mainCurrencyCode, $currencies);
};

// "By category" card: the monthly split, and what each category is charged
// in the current pay period.
$showCategoryCard = !isset($settings['show_category_card']) || (int) $settings['show_category_card'] === 1;
$categorySpend = null;
if ($showCategoryCard && !empty($categoryCost)) {
    $categoryNames = [];
    foreach ($categories as $categoryRowId => $categoryRow) {
        $categoryNames[$categoryRowId] = (int) $categoryRowId === 1 ? translate('no_category', $i18n) : html_entity_decode($categoryRow['name']);
    }
    $categorySpendMonthly = wallos_category_spend_monthly($categoryCost, $categoryNames);
    $categorySpendPeriod = wallos_category_spend_in_period(
        $subscriptions ?? [],
        $categoryNames,
        $budgetPeriodStart,
        $budgetPeriodEnd,
        function ($price, $currencyId) use ($db, $userId) {
            return getPriceConverted($price, $currencyId, $db, $userId);
        }
    );
    // One category says nothing about a split.
    if ($categorySpendMonthly['categories'] >= 2) {
        $categorySpend = ['monthly' => $categorySpendMonthly, 'period' => $categorySpendPeriod];
    }
}
$categoryCardLimit = 6;

$todayLabel = (new IntlDateFormatter($lang, IntlDateFormatter::FULL, IntlDateFormatter::NONE, null, null, 'EEEE, MMMM d'))->format(new DateTime());
$monthLabel = (new IntlDateFormatter($lang, IntlDateFormatter::FULL, IntlDateFormatter::NONE, null, null, 'MMMM yyyy'))->format(new DateTime());
$hasMonthlyBudget = isset($monthlyBudget) && $monthlyBudget > 0;
$budgetPercent = $hasMonthlyBudget ? min(100, ($headlineMonthlyCost ?? 0) / $monthlyBudget * 100) : 0;

// Monthly cost tile: "Spread" (everything, or only monthly-or-shorter cycles,
// per the setting) and "This month" (the calendar month's charges).
$monthlyCostDefaultView = ($monthlyCostMode ?? 'amortized') === 'cashflow' ? 'month' : 'spread';
$monthlyCostSpread = ($monthlyCostMode ?? 'amortized') === 'short_cycles' ? $shortCycleCostPerMonth : ($totalCostPerMonth ?? 0);

$convertCurrency = isset($settings['convertCurrency']) && $settings['convertCurrency'] === 'true';
$dashboardPrice = function ($subscription) use ($convertCurrency, $userData, $currencies, $db, $userId) {
    $mainCurrencyId = $userData['main_currency'];
    if ($convertCurrency && $subscription['currency_id'] != $mainCurrencyId) {
        $price = getPriceConverted($subscription['price'], $subscription['currency_id'], $db, $userId);
        return formatPrice($price, $currencies[$mainCurrencyId]['code'], $currencies);
    }
    return formatPrice($subscription['price'], $currencies[$subscription['currency_id']]['code'], $currencies);
};

$dashboardRow = function ($subscription, $subtitle, $tone = '') use ($dashboardPrice) {
    return render_list_row([
        'id' => $subscription['id'],
        'name' => $subscription['name'],
        'logo' => empty($subscription['logo']) ? '' : 'images/uploads/logos/' . $subscription['logo'],
        'logo_variant' => empty($subscription['logo_variant']) ? null : 'images/uploads/logos/' . $subscription['logo_variant'],
        'logo_text_color' => $subscription['logo_text_color'] ?? null,
        'subtitle' => $subtitle,
        'subtitle_tone' => $tone,
        'trailing' => $dashboardPrice($subscription),
    ]);
};
?>

<?php page_toolbar_start(translate('dashboard', $i18n), $todayLabel); ?>
  <a class="btn btn-primary btn-new" href="subscriptions.php?add" aria-label="<?= translate('new_subscription', $i18n) ?>">
    <i class="fa-solid fa-plus" aria-hidden="true"></i>
    <span class="btn-new-label"><?= translate('new_subscription', $i18n) ?></span>
  </a>
<?php page_toolbar_end(); ?>

<section class="contain dashboard">
    <?php
    if ($isAdmin && $settings['update_notification'] && !is_null($settings['latest_version'])) {
        $latestVersion = $settings['latest_version'];
        if (version_compare($version, $latestVersion) == -1) {
            ?>
            <div class="banner update-banner">
                <i class="fa-solid fa-circle-arrow-up banner-icon" aria-hidden="true"></i>
                <div class="banner-text">
                    <strong><?= translate('new_version_available', $i18n) ?></strong>
                    <span><?= translate('current_version', $i18n) ?>: <?= htmlspecialchars($version) ?> → <?= htmlspecialchars($latestVersion) ?></span>
                </div>
                <a class="btn" href="https://github.com/<?= htmlspecialchars($githubRepository) ?>/releases/tag/<?= htmlspecialchars($releaseTagPrefix) ?><?= htmlspecialchars($latestVersion) ?>"
                    target="_blank" rel="noreferrer"><?= translate('release_notes', $i18n) ?></a>
            </div>
            <?php
        }
    }
    if ($demoMode) {
        ?>
        <div class="banner demo-banner">
            <i class="fa-solid fa-circle-info banner-icon" aria-hidden="true"></i>
            <div class="banner-text">
                <strong>Demo Mode</strong>
                <span>Certain actions and settings are disabled. The database is reset every 120 minutes.</span>
            </div>
        </div>
        <?php
    }
    ?>

    <div class="stat-tiles">
        <div class="stat-tile" id="monthly-cost-tile">
            <div class="stat-tile-head">
                <div class="stat-tile-label"><?= translate('monthly_cost', $i18n) ?></div>
                <div class="segmented" role="group" aria-label="<?= translate('monthly_cost', $i18n) ?>">
                    <?php foreach (['spread' => 'monthly_cost_spread', 'month' => 'monthly_cost_this_month'] as $costView => $costViewLabel) { ?>
                        <button type="button" class="segmented-option<?= $costView === $monthlyCostDefaultView ? ' is-selected' : '' ?>" data-cost-view="<?= $costView ?>"
                            aria-pressed="<?= $costView === $monthlyCostDefaultView ? 'true' : 'false' ?>"><?= translate($costViewLabel, $i18n) ?></button>
                    <?php } ?>
                </div>
            </div>
            <div data-cost-panel="spread"<?= $monthlyCostDefaultView === 'spread' ? '' : ' class="hide"' ?>>
                <div class="stat-tile-value"><?= $money($monthlyCostSpread) ?></div>
                <div class="stat-tile-caption">
                    <?php if (($excludedYearlyCost ?? 0) > 0) { ?>
                        <?= sprintf(translate('plus_yearly_in_longer_plans', $i18n), $money($excludedYearlyCost)) ?>
                    <?php } else { ?>
                        <?= sprintf(translate('n_active_subscriptions', $i18n), $activeSubscriptions ?? 0) ?>
                    <?php } ?>
                </div>
            </div>
            <div data-cost-panel="month"<?= $monthlyCostDefaultView === 'month' ? '' : ' class="hide"' ?>>
                <div class="stat-tile-value"><?= $money($chargesThisMonth ?? 0) ?></div>
                <div class="stat-tile-caption"><?= sprintf(translate('charges_in_month', $i18n), htmlspecialchars($monthLabel)) ?></div>
            </div>
        </div>
        <div class="stat-tile">
            <div class="stat-tile-label"><?= translate('amount_due', $i18n) ?></div>
            <div class="stat-tile-value"><?= $money($amountDueThisMonth ?? 0) ?></div>
            <div class="stat-tile-caption"><?= htmlspecialchars($monthLabel) ?></div>
        </div>
        <?php if (!empty($periodDiffersFromCalendarMonth)) { ?>
            <a class="stat-tile stat-tile-link" href="stats.php?period=pay">
                <div class="stat-tile-label"><?= translate('due_this_pay_period', $i18n) ?></div>
                <div class="stat-tile-value"><?= $money($amountNeededThisPeriod ?? 0) ?></div>
                <div class="stat-tile-caption"><?= htmlspecialchars($budgetPeriodLabel ?? '', ENT_QUOTES, 'UTF-8') ?></div>
            </a>
        <?php } ?>
        <div class="stat-tile">
            <div class="stat-tile-label"><?= translate('yearly_cost', $i18n) ?></div>
            <div class="stat-tile-value"><?= $money($totalCostPerYear ?? 0) ?></div>
            <div class="stat-tile-caption"><?= translate('at_current_prices', $i18n) ?></div>
        </div>
        <?php if ($hasMonthlyBudget) { ?>
            <div class="stat-tile">
                <div class="stat-tile-label"><?= translate('budget_remaining', $i18n) ?></div>
                <div class="stat-tile-value<?= !empty($monthlyOverBudgetAmount) ? ' is-danger' : '' ?>"><?= $money($monthlyBudgetLeft) ?></div>
                <div class="meter<?= !empty($monthlyOverBudgetAmount) ? ' is-danger' : '' ?>" role="progressbar"
                    aria-label="<?= translate('budget_used', $i18n) ?>" aria-valuenow="<?= round($budgetPercent) ?>"
                    aria-valuemin="0" aria-valuemax="100"><span style="width: <?= number_format($budgetPercent, 1, '.', '') ?>%"></span></div>
                <div class="stat-tile-caption">
                    <?php if (!empty($monthlyOverBudgetAmount)) { ?>
                        <?= translate('over_budget', $i18n) ?>: <?= $money($monthlyOverBudgetAmount) ?>
                    <?php } else { ?>
                        <?= sprintf(translate('percent_of_budget', $i18n), number_format($budgetPercent, 0), $money($monthlyBudget)) ?>
                    <?php } ?>
                </div>
            </div>
        <?php } else { ?>
            <a class="stat-tile stat-tile-link" href="settings.php">
                <div class="stat-tile-label"><?= translate('monthly_budget', $i18n) ?></div>
                <div class="stat-tile-value is-muted"><?= translate('not_set', $i18n) ?></div>
                <div class="stat-tile-caption is-tint"><?= translate('set_a_budget', $i18n) ?> <i class="fa-solid fa-chevron-right" aria-hidden="true"></i></div>
            </a>
        <?php } ?>
    </div>

    <div class="dashboard-columns">
        <section class="card">
            <header class="card-header">
                <h2 class="card-title"><?= translate('upcoming_payments', $i18n) ?></h2>
                <a class="link-button" href="subscriptions.php"><?= translate('view_all', $i18n) ?></a>
            </header>
            <div class="card-list">
                <?php
                if (empty($upcomingSubscriptions)) {
                    ?>
                    <p class="card-empty"><?= translate('no_upcoming_payments', $i18n) ?></p>
                    <?php
                } else {
                    foreach ($upcomingSubscriptions as $subscription) {
                        $dueText = due_text($subscription['next_payment'], $lang, $i18n);
                        if (!empty($subscription['shared_owner_name'])) {
                            $dueText = sprintf(translate('shared_by', $i18n), $subscription['shared_owner_name']) . ' · ' . $dueText;
                        }
                        echo $dashboardRow($subscription, $dueText);
                    }
                }
                ?>
            </div>
        </section>

        <div class="dashboard-side">
            <?php if ($hasOverdueSubscriptions) { ?>
                <section class="card">
                    <header class="card-header">
                        <h2 class="card-title"><?= translate('overdue_renewals', $i18n) ?></h2>
                    </header>
                    <div class="card-list">
                        <?php foreach ($overdueSubscriptions as $subscription) {
                            $overdueDays = days_overdue($subscription['next_payment']);
                            $overdueText = sprintf(translate('days_overdue', $i18n), $overdueDays) . ' · '
                                . sprintf(translate('was_due', $i18n), formatDate($subscription['next_payment'], $lang));
                            ?>
                            <div class="list-row-group">
                                <?= $dashboardRow($subscription, $overdueText, 'danger') ?>
                                <button type="button" class="btn btn-small" onClick="renewSubscription(event, <?= (int) $subscription['id'] ?>)">
                                    <?= translate('mark_as_paid', $i18n) ?>
                                </button>
                            </div>
                        <?php } ?>
                    </div>
                </section>
            <?php } ?>

            <?php if (!empty($paymentsToReview)) { ?>
                <section class="card">
                    <header class="card-header">
                        <h2 class="card-title"><?= translate('payments_to_review', $i18n) ?></h2>
                    </header>
                    <div class="card-list">
                        <?php foreach ($paymentsToReview as $item) {
                            $needsAmount = $item['status'] === 'pending_amount';
                            $reviewText = translate($needsAmount ? 'amount_needed' : 'unconfirmed', $i18n)
                                . ' · ' . sprintf(translate('was_due', $i18n), formatDate($item['due_date'], $lang));
                            $defaultAmount = $item['ledger_amount'] ?? $item['price'];
                            ?>
                            <div class="list-row-group">
                                <?= $dashboardRow($item, $reviewText, $needsAmount ? 'warning' : '') ?>
                                <button type="button" class="btn btn-small"
                                    onClick="confirmLedgerPayment(event, <?= (int) $item['payment_id'] ?>, <?= htmlspecialchars(json_encode((float) $defaultAmount), ENT_QUOTES) ?>, <?= $needsAmount ? 'true' : 'false' ?>)">
                                    <?= translate($needsAmount ? 'enter_amount' : 'confirm', $i18n) ?>
                                </button>
                            </div>
                        <?php } ?>
                    </div>
                </section>
            <?php } ?>

            <?php if ($hasUpcomingCancellations) { ?>
                <section class="card">
                    <header class="card-header">
                        <h2 class="card-title"><?= translate('upcoming_cancellations', $i18n) ?></h2>
                        <a class="link-button" href="subscriptions.php?notifications=cancellation&amp;sort=cancellation_date"><?= translate('view_all', $i18n) ?></a>
                    </header>
                    <div class="card-list">
                        <?php if (empty($upcomingCancellations)) { ?>
                            <p class="card-empty"><?= sprintf(translate('no_cancellations_this_or_next_month', $i18n), $laterCancellationsCount) ?></p>
                        <?php } ?>
                        <?php foreach ($upcomingCancellations as $subscription) {
                            if (!empty($subscription['locked_until'])) {
                                $cancelText = sprintf(translate('locked_in_until', $i18n), formatDate($subscription['locked_until'], $lang))
                                    . ' · ' . sprintf(translate('cancel_by', $i18n), formatDate($subscription['cancellation_date'], $lang));
                            } elseif (!empty($subscription['renewal'])) {
                                $cancelText = sprintf(translate('cancel_by_to_avoid_renewal_short', $i18n), formatDate($subscription['cancellation_date'], $lang));
                            } else {
                                $cancelText = translate('cancellation', $i18n) . ' · ' . formatDate($subscription['cancellation_date'], $lang);
                            }
                            $cancelSoon = days_until_date($subscription['cancellation_date']) <= 7;
                            echo $dashboardRow($subscription, $cancelText, $cancelSoon ? 'warning' : '');
                        } ?>
                    </div>
                    <?php if ($upcomingCancellationsSaving > 0) { ?>
                        <p class="card-footnote"><?= sprintf(translate('cancelling_these_saves', $i18n), $money($upcomingCancellationsSaving)) ?></p>
                    <?php } ?>
                </section>
            <?php } ?>

            <?php if ($categorySpend !== null) { ?>
                <section class="card category-card" id="category-card">
                    <header class="card-header">
                        <h2 class="card-title"><?= translate('by_category', $i18n) ?></h2>
                        <div class="segmented" role="group" aria-label="<?= translate('by_category', $i18n) ?>">
                            <button type="button" class="segmented-option is-selected" data-category-view="monthly" aria-pressed="true"><?= translate('Monthly', $i18n) ?></button>
                            <button type="button" class="segmented-option" data-category-view="period" aria-pressed="false"
                                title="<?= htmlspecialchars($budgetPeriodLabel ?? '', ENT_QUOTES, 'UTF-8') ?>"><?= translate('this_pay_period', $i18n) ?></button>
                        </div>
                    </header>
                    <?php foreach (['monthly', 'period'] as $view) {
                        $spend = $categorySpend[$view];
                        $visibleRows = array_slice($spend['rows'], 0, $categoryCardLimit);
                        $moreCount = count($spend['rows']) - count($visibleRows);
                        ?>
                        <div class="category-spend<?= $view === 'monthly' ? '' : ' hide' ?>" data-category-panel="<?= $view ?>">
                            <?php if ($view === 'period') { ?>
                                <p class="category-spend-note"><?= htmlspecialchars($budgetPeriodLabel ?? '', ENT_QUOTES, 'UTF-8') ?></p>
                            <?php } ?>
                            <?php if (empty($visibleRows)) { ?>
                                <p class="card-empty"><?= translate('no_payments_this_period', $i18n) ?></p>
                            <?php } else { ?>
                                <ul class="category-spend-list">
                                    <?php foreach ($visibleRows as $row) { ?>
                                        <li>
                                            <a class="category-spend-row" href="subscriptions.php?category=<?= (int) $row['id'] ?>">
                                                <span class="category-dot" style="--dot-hue: <?= (int) $row['hue'] ?>" aria-hidden="true"></span>
                                                <span class="category-spend-name"><?= htmlspecialchars($row['name']) ?></span>
                                                <span class="category-spend-value"><?= $money($row['cost']) ?></span>
                                                <span class="category-spend-bar" style="--dot-hue: <?= (int) $row['hue'] ?>" role="presentation"><span style="width: <?= number_format($row['share'], 1, '.', '') ?>%"></span></span>
                                                <span class="visually-hidden"><?= number_format($row['share'], 0) ?>%</span>
                                            </a>
                                        </li>
                                    <?php } ?>
                                </ul>
                                <?php if ($moreCount > 0) { ?>
                                    <a class="category-spend-more link-button" href="stats.php"><?= sprintf(translate('n_more', $i18n), $moreCount) ?></a>
                                <?php } ?>
                            <?php } ?>
                        </div>
                    <?php } ?>
                </section>
            <?php } ?>

            <?php if (!empty($aiRecommendations)) { ?>
                <section class="card ai-recommendations">
                    <header class="card-header">
                        <h2 class="card-title"><?= translate('ai_recommendations', $i18n) ?></h2>
                    </header>
                    <ul class="ai-recommendations-list">
                        <?php foreach ($aiRecommendations as $recommendation) { ?>
                            <li class="ai-recommendation-item" data-id="<?= (int) $recommendation['id'] ?>">
                                <div class="ai-recommendation-header">
                                    <h3><?= htmlspecialchars($recommendation['title']) ?></h3>
                                    <button type="button" class="icon-button delete-ai-recommendation" title="<?= translate('delete', $i18n) ?>"
                                        aria-label="<?= translate('delete', $i18n) ?>">
                                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                                    </button>
                                </div>
                                <p class="collapsible"><?= htmlspecialchars($recommendation['description']) ?></p>
                                <?php if (!empty($recommendation['savings'])) { ?>
                                    <p class="ai-recommendation-savings"><?= htmlspecialchars($recommendation['savings']) ?></p>
                                <?php } ?>
                            </li>
                        <?php } ?>
                    </ul>
                </section>
            <?php } ?>

            <?php if (isset($periodBudget) && $periodBudget > 0) { ?>
                <section class="card">
                    <header class="card-header">
                        <h2 class="card-title"><?= translate('period_budget', $i18n) ?></h2>
                        <?php if (isset($budgetPeriodLabel)) { ?>
                            <span class="card-header-note"><?= htmlspecialchars($budgetPeriodLabel, ENT_QUOTES, 'UTF-8') ?></span>
                        <?php } ?>
                    </header>
                    <dl class="kv-list">
                        <div class="kv-row"><dt><?= translate('amount_needed_this_period', $i18n) ?></dt><dd><?= $money($amountNeededThisPeriod) ?></dd></div>
                        <div class="kv-row"><dt><?= translate('budget', $i18n) ?></dt><dd><?= $money($periodBudget) ?></dd></div>
                        <div class="kv-row"><dt><?= translate('budget_remaining', $i18n) ?></dt><dd><?= $money($periodBudgetLeft) ?></dd></div>
                        <?php if (isset($periodOverBudgetAmount) && $periodOverBudgetAmount > 0) { ?>
                            <div class="kv-row"><dt><?= translate('over_budget', $i18n) ?></dt><dd class="is-danger"><?= $money($periodOverBudgetAmount) ?></dd></div>
                        <?php } ?>
                    </dl>
                    <?php if (isset($periodBudgetUsed)) { ?>
                        <div class="card-meter">
                            <div class="meter<?= !empty($periodOverBudgetAmount) ? ' is-danger' : '' ?>" role="progressbar"
                                aria-label="<?= translate('budget_used', $i18n) ?>" aria-valuenow="<?= round($periodBudgetUsed) ?>"
                                aria-valuemin="0" aria-valuemax="100"><span style="width: <?= number_format($periodBudgetUsed, 1, '.', '') ?>%"></span></div>
                        </div>
                    <?php } ?>
                </section>
            <?php } ?>

            <?php if ((isset($inactiveSubscriptions) && $inactiveSubscriptions > 0) || ($planDowngradeSavingsPerMonth ?? 0) > 0) { ?>
                <section class="card">
                    <header class="card-header">
                        <h2 class="card-title"><?= translate('your_savings', $i18n) ?></h2>
                    </header>
                    <dl class="kv-list">
                        <?php if ($inactiveSubscriptions > 0) { ?>
                            <div class="kv-row"><dt><?= translate('inactive_subscriptions', $i18n) ?></dt><dd><?= (int) $inactiveSubscriptions ?></dd></div>
                        <?php } ?>
                        <?php if (!empty($cancelledTrials)) { ?>
                            <div class="kv-row"><dt><?= translate('cancelled_trials', $i18n) ?></dt><dd><?= (int) $cancelledTrials ?></dd></div>
                        <?php } ?>
                        <?php if (($planDowngradeSavingsPerMonth ?? 0) > 0) { ?>
                            <div class="kv-row"><dt><?= translate('saved_by_downgrades', $i18n) ?></dt><dd><?= $money($planDowngradeSavingsPerMonth) ?></dd></div>
                        <?php } ?>
                        <?php if (isset($totalSavingsPerMonth) && $totalSavingsPerMonth > 0) { ?>
                            <div class="kv-row"><dt><?= translate('monthly_savings', $i18n) ?></dt><dd><?= $money($totalSavingsPerMonth) ?></dd></div>
                            <div class="kv-row"><dt><?= translate('yearly_savings', $i18n) ?></dt><dd><?= $money($totalSavingsPerMonth * 12) ?></dd></div>
                        <?php } ?>
                    </dl>
                </section>
            <?php } ?>
        </div>
    </div>
</section>

<?php
// Get all subscriptions for user details lookup
$query = 'SELECT * FROM subscriptions WHERE user_id = :userId';
$stmt = $db->prepare($query);
$stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
$subscriptions = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $subscriptions[] = $row;
}
require_once 'includes/subscription_details_popup.php';
?>

<script src="scripts/dashboard.js?<?= $version ?>"></script>

<?php
require_once 'includes/footer.php';
?>
