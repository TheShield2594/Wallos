<?php
require_once __DIR__ . '/budget_period_calculations.php';
require_once __DIR__ . '/currency_rates.php';
require_once __DIR__ . '/default_names.php';
require_once __DIR__ . '/recurrence.php';
require_once __DIR__ . '/intro_pricing.php';
require_once __DIR__ . '/households.php';
require_once __DIR__ . '/monthly_cost.php';
require_once __DIR__ . '/price_changes.php';

function getPricePerMonth($cycle, $frequency, $price)
{
    switch ($cycle) {
        case 1:
            $numberOfPaymentsPerMonth = (30 / $frequency);
            return $price * $numberOfPaymentsPerMonth;
        case 2:
            $numberOfPaymentsPerMonth = (4.35 / $frequency);
            return $price * $numberOfPaymentsPerMonth;
        case 3:
            $numberOfPaymentsPerMonth = (1 / $frequency);
            return $price * $numberOfPaymentsPerMonth;
        case 4:
            $numberOfMonths = (12 * $frequency);
            return $price / $numberOfMonths;
        case 5:
            return 0;
    }
}

function getPriceConverted($price, $currency, $database, $userId)
{
    return wallos_convert_price($price, $currency, $database, $userId);
}

// Get categories
$categories = array();
$query = "SELECT * FROM categories WHERE user_id = :userId ORDER BY `order` ASC";
$stmt = $db->prepare($query);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
  $categoryId = $row['id'];
  $categories[$categoryId] = $row;
  $categories[$categoryId]['count'] = 0;
  $categoryCost[$categoryId]['cost'] = 0;
  $categoryCost[$categoryId]['name'] = $row['name'];
}

// Get payment methods
$paymentMethods = array();
$query = "SELECT * FROM payment_methods WHERE user_id = :userId AND enabled = 1";
$stmt = $db->prepare($query);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
  $paymentMethodId = $row['id'];
  $paymentMethods[$paymentMethodId] = $row;
  $paymentMethods[$paymentMethodId]['count'] = 0;
  $paymentMethodsCount[$paymentMethodId]['count'] = 0;
  $paymentMethodsCount[$paymentMethodId]['name'] = $row['name'];
}

//Get household members
$members = array();
$query = "SELECT * FROM household WHERE user_id = :userId";
$stmt = $db->prepare($query);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
  $memberId = $row['id'];
  $members[$memberId] = $row;
  $members[$memberId]['count'] = 0;
  $memberCost[$memberId]['cost'] = 0;
  $memberCost[$memberId]['name'] = $row['name'];
}

// Unfiltered counts for filter menu display (so non-selected items remain visible when a filter is active)
$stmt = $db->prepare("SELECT category_id, COUNT(*) as cnt FROM subscriptions WHERE user_id = :userId GROUP BY category_id");
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$r = $stmt->execute();
$menuCategoryCounts = [];
while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
    $menuCategoryCounts[$row['category_id']] = $row['cnt'];
}

$stmt = $db->prepare("SELECT payer_user_id, COUNT(*) as cnt FROM subscriptions WHERE user_id = :userId GROUP BY payer_user_id");
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$r = $stmt->execute();
$menuMemberCounts = [];
while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
    $menuMemberCounts[$row['payer_user_id']] = $row['cnt'];
}

$stmt = $db->prepare("SELECT payment_method_id, COUNT(*) as cnt FROM subscriptions WHERE user_id = :userId AND inactive = 0 GROUP BY payment_method_id");
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$r = $stmt->execute();
$menuPaymentCounts = [];
while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
    $menuPaymentCounts[$row['payment_method_id']] = $row['cnt'];
}

$activeSubscriptions = 0;
$inactiveSubscriptions = 0;
$cancelledTrials = 0;
// Calculate total monthly price
$mostExpensiveSubscription = array();
$mostExpensiveSubscription['price'] = 0;
$amountDueThisMonth = 0;
// Everything charged in the calendar month, past days included (the
// "This month" view of the monthly cost).
$chargesThisMonth = 0;
// The amortized monthly cost split by cycle length (see includes/monthly_cost.php).
$shortCycleCostPerMonth = 0;
$longCycleCostPerMonth = 0;
$totalCostPerMonth = 0;
$totalCostPerYearSum = 0;
$totalSavingsPerMonth = 0;
$totalCostsInReplacementsPerMonth = 0;

$statsSubtitleParts = [];
$query = "SELECT id, name, price, logo, logo_text_color, logo_variant, frequency, cycle, currency_id, next_payment, payer_user_id, category_id, payment_method_id, inactive, replacement_subscription_id, start_date, auto_renew, billing_anchor_day, end_date, total_payments, payments_made, ended_reason, intro_type, intro_price, intro_end_date, intro_converted_on FROM subscriptions";
$conditions = [];
$params = [];

if (isset($_GET['member']) && $_GET['member'] !== '') {
    $memberIds = array_map('intval', explode(',', $_GET['member']));
    $conditions[] = "payer_user_id IN (" . implode(',', $memberIds) . ")";
    foreach ($memberIds as $mid) {
        if (isset($members[$mid])) {
            $statsSubtitleParts[] = $members[$mid]['name'];
        }
    }
}

if (isset($_GET['category']) && $_GET['category'] !== '') {
    $categoryIds = array_map('intval', explode(',', $_GET['category']));
    $conditions[] = "category_id IN (" . implode(',', $categoryIds) . ")";
    // A category still carrying its untouched default name is recognised
    // against the account's own creation-language default, not a literal
    // English string - #1216 gives every language its own default, so an
    // account created in German never had an English name to match here.
    $noCategoryDefaultName = default_no_category_name($userData['language'] ?? 'en');
    foreach ($categoryIds as $cid) {
        if (isset($categories[$cid])) {
            $statsSubtitleParts[] = $categories[$cid]['name'] === $noCategoryDefaultName ? translate("no_category", $i18n) : $categories[$cid]['name'];
        }
    }
}

if (isset($_GET['payment']) && $_GET['payment'] !== '') {
    $paymentIds = array_map('intval', explode(',', $_GET['payment']));
    $conditions[] = "payment_method_id IN (" . implode(',', $paymentIds) . ")";
    foreach ($paymentIds as $pid) {
        if (isset($paymentMethodsCount[$pid])) {
            $statsSubtitleParts[] = $paymentMethodsCount[$pid]['name'];
        }
    }
}

$conditions[] = "user_id = :userId";
$params[':userId'] = $userId;

if (!empty($conditions)) {
    $query .= " WHERE " . implode(' AND ', $conditions);
}

$stmt = $db->prepare($query);
$statsSubtitle = !empty($statsSubtitleParts) ? '(' . implode(', ', $statsSubtitleParts) . ')' : "";

foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value, SQLITE3_INTEGER);
}

$result = $stmt->execute();
$usesMultipleCurrencies = false;
$subscriptions = [];

if ($result) {
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $subscriptions[] = $row;
    }
    // "Household" scope (statistics page): add what other members share with
    // the account's household, unless the figures are filtered by the
    // account's own members, categories or payment methods. Their currency
    // is mapped onto the account's currencies (see includes/households.php).
    if (($statsScope ?? 'mine') === 'household' && empty($_GET['member']) && empty($_GET['category']) && empty($_GET['payment'])) {
        foreach (wallos_shared_subscriptions($db, $userId) as $sharedRow) {
            $subscriptions[] = $sharedRow;
        }
    }
    if (isset($subscriptions)) {
        $replacementSubscriptions = array();

        foreach ($subscriptions as $subscription) {
            $name = $subscription['name'];
            $price = $subscription['price'];
            $logo = $subscription['logo'];
            $frequency = $subscription['frequency'];
            $cycle = $subscription['cycle'];
            $currency = $subscription['currency_id'];
            if ($currency != $userData['main_currency']) {
                $usesMultipleCurrencies = true;
            }
            $next_payment = $subscription['next_payment'];
            $payerId = $subscription['payer_user_id'];
            if (isset($members[$payerId])) {
                $members[$payerId]['count'] += 1;
            }
            $categoryId = $subscription['category_id'];
            if (isset($categories[$categoryId])) {
                $categories[$categoryId]['count'] += 1;
            }
            $paymentMethodId = $subscription['payment_method_id'];
            if (isset($paymentMethods[$paymentMethodId])) {
                $paymentMethods[$paymentMethodId]['count'] += 1;
            }
            $inactive = $subscription['inactive'];
            $replacementSubscriptionId = $subscription['replacement_subscription_id'];
            // During a free trial or promo the monthly cost is the intro
            // price; a cancelled trial is saved at the regular price.
            $hasIntro = wallos_intro_active($subscription);
            $originalSubscriptionPrice = getPriceConverted($inactive == 0 ? wallos_current_price($subscription) : $price, $currency, $db, $userId);
            $price = getPricePerMonth($cycle, $frequency, $originalSubscriptionPrice);

            if ($inactive == 0) {
                // A fixed-term plan only costs the payments it has left in
                // the coming year, and an intro period only its own months;
                // everything else costs twelve months.
                if ($cycle != 5 && (!empty($subscription['end_date']) || $hasIntro)) {
                    $yearAhead = (new DateTime('today'))->modify('+1 year -1 day');
                    $totalCostPerYearSum += getPriceConverted(
                        wallos_price_of_occurrences($subscription, wallos_occurrences_in_range($subscription, new DateTime('today'), $yearAhead)),
                        $currency, $db, $userId
                    );
                } else {
                    $totalCostPerYearSum += $price * 12;
                }
                if ($cycle != 5) {
                    $activeSubscriptions++;
                    if (isset($paymentMethodsCount[$paymentMethodId])) {
                        $paymentMethodsCount[$paymentMethodId]['count'] += 1;
                    }
                }
                $totalCostPerMonth += $price;
                if (wallos_is_short_cycle($cycle, $frequency)) {
                    $shortCycleCostPerMonth += $price;
                } elseif ($cycle != 5) {
                    $longCycleCostPerMonth += $price;
                }
                if (isset($memberCost[$payerId])) {
                    $memberCost[$payerId]['cost'] += $price;
                }
                if (isset($categoryCost[$categoryId])) {
                    $categoryCost[$categoryId]['cost'] += $price;
                }
                if ($price > $mostExpensiveSubscription['price']) {
                    $mostExpensiveSubscription['price'] = $price;
                    $mostExpensiveSubscription['name'] = $name;
                    $mostExpensiveSubscription['logo'] = $logo;
                    $mostExpensiveSubscription['logo_text_color'] = $subscription['logo_text_color'] ?? null;
                    $mostExpensiveSubscription['logo_variant'] = $subscription['logo_variant'] ?? null;
                }

                if ($cycle != 5) {
                    // Amount due for the rest of this month: every payment
                    // date from today to the end of the month, up to the end
                    // of a fixed-term plan.
                    $dueThisMonth = wallos_occurrences_in_range(
                        $subscription,
                        new DateTime('today'),
                        new DateTime('last day of this month'),
                        false
                    );
                    $amountDueThisMonth += getPriceConverted(wallos_price_of_occurrences($subscription, $dueThisMonth), $currency, $db, $userId);

                    $chargedThisMonth = wallos_occurrences_in_range(
                        $subscription,
                        new DateTime('first day of this month'),
                        new DateTime('last day of this month')
                    );
                    $chargesThisMonth += getPriceConverted(wallos_price_of_occurrences($subscription, $chargedThisMonth), $currency, $db, $userId);
                }
            } elseif (($subscription['ended_reason'] ?? null) === 'completed') {
                // A plan that ran its course was paid off, not cancelled:
                // it is not a saving. Its final payments still count among
                // this month's charges (the plan's end date bounds them).
                if ($cycle != 5) {
                    $chargedThisMonth = wallos_occurrences_in_range(
                        $subscription,
                        new DateTime('first day of this month'),
                        new DateTime('last day of this month')
                    );
                    $chargesThisMonth += getPriceConverted(wallos_price_of_occurrences($subscription, $chargedThisMonth), $currency, $db, $userId);
                }
                continue;
            } else {
                $inactiveSubscriptions++;
                $totalSavingsPerMonth += $price;
                if (wallos_is_cancelled_trial(array_merge($subscription, ['inactive' => 1]))) {
                    $cancelledTrials++;
                }

                // Check if it has a replacement subscription and if it was not already counted
                if ($replacementSubscriptionId && !in_array($replacementSubscriptionId, $replacementSubscriptions)) {
                    $query = "SELECT price, currency_id, cycle, frequency FROM subscriptions WHERE id = :replacementSubscriptionId AND user_id = :userId";
                    $stmt = $db->prepare($query);
                    $stmt->bindValue(':replacementSubscriptionId', $replacementSubscriptionId, SQLITE3_INTEGER);
                    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
                    $result = $stmt->execute();
                    $replacementSubscription = $result->fetchArray(SQLITE3_ASSOC);
                    if ($replacementSubscription) {
                        $replacementSubscriptionPrice = getPriceConverted($replacementSubscription['price'], $replacementSubscription['currency_id'], $db, $userId);
                        $replacementSubscriptionPrice = getPricePerMonth($replacementSubscription['cycle'], $replacementSubscription['frequency'], $replacementSubscriptionPrice);
                        $totalCostsInReplacementsPerMonth += $replacementSubscriptionPrice;
                    }
                }

                $replacementSubscriptions[] = $replacementSubscriptionId;
            }

        }

        // Subtract the total cost of replacement subscriptions from the total savings
        $totalSavingsPerMonth -= $totalCostsInReplacementsPerMonth;

        // Plan changes of the active subscriptions on this page (filters
        // apply): downgrades are savings, provider increases inflation.
        $statsSubscriptionIds = [];
        foreach ($subscriptions as $statsRow) {
            if (empty($statsRow['shared_owner_name']) && (int) $statsRow['inactive'] === 0 && isset($statsRow['id'])) {
                $statsSubscriptionIds[(int) $statsRow['id']] = true;
            }
        }
        $priceChangeTotals = wallos_price_change_totals(
            array_filter(wallos_active_price_changes($db, $userId), fn($change) => isset($statsSubscriptionIds[(int) $change['subscription_id']])),
            date('Y-m-d'),
            fn($amount, $currencyId) => getPriceConverted($amount, $currencyId, $db, $userId)
        );
        // Shown as its own line under "Your savings": the existing figures
        // are labelled as savings on inactive subscriptions.
        $planDowngradeSavingsPerMonth = $priceChangeTotals['downgrade_monthly'];

        // Yearly price (fixed-term plans only count their remaining payments)
        $totalCostPerYear = $totalCostPerYearSum;

        // Calculate average subscription monthly cost
        if ($activeSubscriptions > 0) {
            $averageSubscriptionCost = $totalCostPerMonth / $activeSubscriptions;
        } else {
            $totalCostPerYear = 0;
            $averageSubscriptionCost = 0;
        }
    } else {
        $totalCostPerYear = 0;
        $averageSubscriptionCost = 0;
    }
}

$today = new DateTime('now');
$budgetPeriodType = sanitizeBudgetPeriodType($userData['budget_period_type'] ?? 'monthly');
$budgetPeriodAnchorDate = sanitizeBudgetAnchorDate($userData['budget_period_anchor_date'] ?? getDefaultBudgetAnchorDate());
$activeBudgetPeriod = getActiveBudgetPeriod($today, $budgetPeriodType, $budgetPeriodAnchorDate);
$budgetPeriodStart = $activeBudgetPeriod['start'];
$budgetPeriodEnd = $activeBudgetPeriod['end'];
$budgetPeriodLabel = $activeBudgetPeriod['label'];

// A monthly period whose anchor lands on the calendar month's boundaries is
// identical to the plain monthly budget, so there's nothing distinct to show.
$calendarMonthStart = new DateTime($today->format('Y-m-01'));
$calendarMonthEnd = new DateTime($today->format('Y-m-t'));
$periodDiffersFromCalendarMonth = $budgetPeriodStart->format('Y-m-d') !== $calendarMonthStart->format('Y-m-d')
    || $budgetPeriodEnd->format('Y-m-d') !== $calendarMonthEnd->format('Y-m-d');

$amountNeededThisPeriod = computeAmountNeededInPeriod($subscriptions ?? [], $today, $budgetPeriodEnd, $db, $userId);

// The headline "Monthly cost" and the monthly budget follow the user's
// choice: everything spread evenly, only monthly-or-shorter cycles, or this
// month's charges. Averages, splits and yearly figures stay amortized.
$monthlyCostMode = wallos_sanitize_monthly_cost_mode($settings['monthly_cost_mode'] ?? null);
$headlineMonthlyCost = wallos_headline_monthly_cost($monthlyCostMode, $totalCostPerMonth, $shortCycleCostPerMonth, $chargesThisMonth);
// Shown beside the short-cycles figure so longer plans don't disappear.
$excludedYearlyCost = $monthlyCostMode === 'short_cycles' ? $longCycleCostPerMonth * 12 : 0;

$showVsMonthlyBudgetGraph = false;
$vsMonthlyBudgetDataPoints = [];
if (isset($userData['budget']) && $userData['budget'] > 0) {
    $monthlyBudget = $userData['budget'];
    $monthlyBudgetLeft = max(0, $monthlyBudget - $headlineMonthlyCost);
    $monthlyBudgetUsed = min(100, ($headlineMonthlyCost / $monthlyBudget) * 100);
    if ($headlineMonthlyCost > $monthlyBudget) {
        $monthlyOverBudgetAmount = $headlineMonthlyCost - $monthlyBudget;
    }
    $showVsMonthlyBudgetGraph = true;
    $vsMonthlyBudgetDataPoints = [
        [
            "label" => translate('budget_remaining', $i18n),
            "y" => $monthlyBudgetLeft,
        ],
        [
            "label" => translate('monthly_cost', $i18n),
            "y" => $headlineMonthlyCost,
        ],
    ];
    // Backwards compatibility for pages still referencing legacy monthly budget variables.
    $budget = $monthlyBudget;
    $budgetLeft = $monthlyBudgetLeft;
    $budgetUsed = $monthlyBudgetUsed;
    $overBudgetAmount = $monthlyOverBudgetAmount ?? 0;
}

$showVsPeriodBudgetGraph = false;
$vsPeriodBudgetDataPoints = [];
if ($periodDiffersFromCalendarMonth && isset($userData['period_budget']) && $userData['period_budget'] > 0) {
    $periodBudget = $userData['period_budget'];
    $periodBudgetLeft = max(0, $periodBudget - $amountNeededThisPeriod);
    $periodBudgetUsed = min(100, ($amountNeededThisPeriod / $periodBudget) * 100);
    if ($amountNeededThisPeriod > $periodBudget) {
        $periodOverBudgetAmount = $amountNeededThisPeriod - $periodBudget;
    }
    $showVsPeriodBudgetGraph = true;
    $vsPeriodBudgetDataPoints = [
        [
            "label" => translate('budget_remaining', $i18n),
            "y" => $periodBudgetLeft,
        ],
        [
            "label" => translate('amount_needed_this_period', $i18n),
            "y" => $amountNeededThisPeriod,
        ],
    ];
}

// Backwards compatibility for pages still referencing legacy graph variables.
$showVsBudgetGraph = $showVsMonthlyBudgetGraph;
$vsBudgetDataPoints = $vsMonthlyBudgetDataPoints;

$showCantConverErrorMessage = false;
if ($usesMultipleCurrencies) {
    $query = "SELECT api_key FROM fixer WHERE user_id = :userId";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    if ($result->fetchArray(SQLITE3_ASSOC) === false) {
        $showCantConverErrorMessage = true;
    }
}

$query = "SELECT * FROM total_yearly_cost WHERE user_id = :userId";
$stmt = $db->prepare($query);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();

$totalMonthlyCostDataPoints = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $totalMonthlyCostDataPoints[] = [
        "label" => html_entity_decode($row['date']),
        "y" => round($row['cost'] / 12, 2),
    ];
}

$showTotalMonthlyCostGraph = count($totalMonthlyCostDataPoints) > 1;

?>
