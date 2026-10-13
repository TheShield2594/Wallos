<?php

require_once 'includes/header.php';
require_once 'includes/getdbkeys.php';
require_once 'includes/logo_theme_variant.php';
require_once 'includes/upcoming_payments.php';
require_once 'includes/upcoming_cancellations.php';
require_once 'includes/ui_helpers.php';

function formatPrice($price, $currencyCode, $currencies)
{
    $formattedPrice = CurrencyFormatter::format($price, $currencyCode);
    if (strstr($formattedPrice, $currencyCode)) {
        $symbol = $currencyCode;

        foreach ($currencies as $currency) {

            if ($currency['code'] === $currencyCode) {
                if ($currency['symbol'] != "") {
                    $symbol = $currency['symbol'];
                }
                break;
            }
        }
        $formattedPrice = str_replace($currencyCode, $symbol, $formattedPrice);
    }

    return $formattedPrice;
}

function formatDate($date, $lang = 'en')
{
    $currentYear = date('Y');
    $dateYear = date('Y', strtotime($date));

    // Determine the date format based on whether the year matches the current year
    $dateFormat = ($currentYear == $dateYear) ? 'MMM d' : 'MMM yyyy';

    // Try to create an IntlDateFormatter; if it fails, fallback to 'en'
    try {
        $formatter = new IntlDateFormatter(
            $lang,
            IntlDateFormatter::SHORT,
            IntlDateFormatter::NONE,
            null,
            null,
            $dateFormat
        );

        if (!$formatter) {
            throw new Exception('Failed to create IntlDateFormatter with language: ' . $lang);
        }
    } catch (Throwable $e) {
        $lang = 'en'; // Fallback to English on error
        $formatter = new IntlDateFormatter(
            $lang,
            IntlDateFormatter::SHORT,
            IntlDateFormatter::NONE,
            null,
            null,
            $dateFormat
        );
    }

    // Format the date
    $formattedDate = $formatter->format(new DateTime($date));

    return $formattedDate;
}

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

// Fetch enabled subscriptions with manual renewal that are overdue
$stmt = $db->prepare("SELECT id, logo, logo_text_color, logo_variant, name, price, currency_id, next_payment, inactive, auto_renew FROM subscriptions WHERE user_id = :userId AND next_payment < date('now') AND auto_renew = 0 AND inactive = 0 AND cycle != 5 ORDER BY next_payment ASC");
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
$overdueSubscriptions = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $overdueSubscriptions[] = $row;
}
$hasOverdueSubscriptions = !empty($overdueSubscriptions);

// Fetch the subscriptions whose cancellation reminder is still ahead (shared with
// the statistics page, so both stay in step).
$upcomingCancellations = get_upcoming_cancellations($db, $userId);
$hasUpcomingCancellations = !empty($upcomingCancellations);

require_once 'includes/stats_calculations.php';

// Get AI Recommendations for user
$stmt = $db->prepare("SELECT * FROM ai_recommendations WHERE user_id = :userId");
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
$aiRecommendations = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $aiRecommendations[] = $row;
}

$mainCurrencyCode = $currencies[$userData['main_currency']]['code'];
$money = function ($amount) use ($mainCurrencyCode, $currencies) {
    return formatPrice($amount, $mainCurrencyCode, $currencies);
};

$todayLabel = (new IntlDateFormatter($lang, IntlDateFormatter::FULL, IntlDateFormatter::NONE, null, null, 'EEEE, MMMM d'))->format(new DateTime());
$monthLabel = (new IntlDateFormatter($lang, IntlDateFormatter::FULL, IntlDateFormatter::NONE, null, null, 'MMMM yyyy'))->format(new DateTime());
$hasMonthlyBudget = isset($monthlyBudget) && $monthlyBudget > 0;
$budgetPercent = $hasMonthlyBudget ? min(100, ($totalCostPerMonth ?? 0) / $monthlyBudget * 100) : 0;

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
        <div class="stat-tile">
            <div class="stat-tile-label"><?= translate('monthly_cost', $i18n) ?></div>
            <div class="stat-tile-value"><?= $money($totalCostPerMonth ?? 0) ?></div>
            <div class="stat-tile-caption"><?= sprintf(translate('n_active_subscriptions', $i18n), $activeSubscriptions ?? 0) ?></div>
        </div>
        <div class="stat-tile">
            <div class="stat-tile-label"><?= translate('amount_due', $i18n) ?></div>
            <div class="stat-tile-value"><?= $money($amountDueThisMonth ?? 0) ?></div>
            <div class="stat-tile-caption"><?= htmlspecialchars($monthLabel) ?></div>
        </div>
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
                        echo $dashboardRow($subscription, due_text($subscription['next_payment'], $lang, $i18n));
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

            <?php if ($hasUpcomingCancellations) { ?>
                <section class="card">
                    <header class="card-header">
                        <h2 class="card-title"><?= translate('upcoming_cancellations', $i18n) ?></h2>
                    </header>
                    <div class="card-list">
                        <?php foreach ($upcomingCancellations as $subscription) {
                            echo $dashboardRow($subscription, translate('cancellation', $i18n) . ' · ' . formatDate($subscription['cancellation_date'], $lang));
                        } ?>
                    </div>
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

            <?php if (isset($inactiveSubscriptions) && $inactiveSubscriptions > 0) { ?>
                <section class="card">
                    <header class="card-header">
                        <h2 class="card-title"><?= translate('your_savings', $i18n) ?></h2>
                    </header>
                    <dl class="kv-list">
                        <div class="kv-row"><dt><?= translate('inactive_subscriptions', $i18n) ?></dt><dd><?= (int) $inactiveSubscriptions ?></dd></div>
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
