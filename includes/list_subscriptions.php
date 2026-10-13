<?php

require_once 'i18n/getlang.php';
require_once __DIR__ . '/currency_rates.php';
require_once __DIR__ . '/ui_helpers.php';

function getBillingCycle($cycle, $frequency, $i18n)
{
    switch ($cycle) {
        case 1:
            return $frequency == 1 ? translate('Daily', $i18n) : $frequency . " " . translate('days', $i18n);
        case 2:
            return $frequency == 1 ? translate('Weekly', $i18n) : $frequency . " " . translate('weeks', $i18n);
        case 3:
            return $frequency == 1 ? translate('Monthly', $i18n) : $frequency . " " . translate('months', $i18n);
        case 4:
            return $frequency == 1 ? translate('Yearly', $i18n) : $frequency . " " . translate('years', $i18n);
        case 5:
            return translate('One-time', $i18n);
    }
}

function getSubscriptionProgress($cycle, $frequency, $next_payment)
{
    if ($cycle === 5) {
        return 0;
    }

    $nextPaymentDate = new DateTime($next_payment);
    $currentDate = new DateTime((new DateTime('now'))->format('Y-m-d'));

    $paymentCycleDays = 30; // Default to monthly
    if ($cycle === 1) {
        $paymentCycleDays = 1 * $frequency;
    } else if ($cycle === 2) {
        $paymentCycleDays = 7 * $frequency;
    } else if ($cycle === 3) {
        $paymentCycleDays = 30 * $frequency;
    } else if ($cycle === 4) {
        $paymentCycleDays = 365 * $frequency;
    }

    if ($paymentCycleDays <= 0) {
        return 0;
    }

    // next_payment can be many cycles away from today (a stale value, or
    // several missed renewal runs), so we can't always assume it's within a
    // single cycle of "now". Walk back however many whole cycles are needed
    // so the window we measure progress against is the one that actually
    // contains today.
    $daysUntilNextPayment = $currentDate->diff($nextPaymentDate)->days;
    $cyclesBack = $currentDate <= $nextPaymentDate
        ? max(1, (int) ceil($daysUntilNextPayment / $paymentCycleDays))
        : 1;

    $lastPaymentDate = clone $nextPaymentDate;
    $lastPaymentDate->modify('-' . ($cyclesBack * $paymentCycleDays) . ' days');

    $daysSinceLastPayment = $lastPaymentDate->diff($currentDate)->days;
    $subscriptionProgress = ($daysSinceLastPayment / $paymentCycleDays) * 100;

    return floor($subscriptionProgress);
}

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


function getPriceConverted($price, $currency, $database)
{
    return wallos_convert_price($price, $currency, $database);
}

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

/*
  Extra fields the list rows need on top of the display values each caller
  already computes: the raw dates, and the monthly cost in the main currency
  for the section footers (independent of the display-price settings).
*/
function addListRowFields(&$printRow, $subscription, $mainCurrencyId, $db)
{
    $printRow['next_payment_raw'] = $subscription['next_payment'];
    $printRow['start_date'] = $subscription['start_date'] ?? '';
    $printRow['cycle'] = (int) $subscription['cycle'];
    $monthly = getPricePerMonth((int) $subscription['cycle'], (int) $subscription['frequency'], floatval($subscription['price']));
    if ($subscription['currency_id'] != $mainCurrencyId) {
        $monthly = getPriceConverted($monthly, $subscription['currency_id'], $db);
    }
    $printRow['monthly_main'] = $monthly;
}

function listRowSubtitle($subscription, $i18n, $lang)
{
    $cycle = $subscription['billing_cycle'];
    if ($subscription['inactive']) {
        $disabledLabel = translate('disabled', $i18n);
        return [mb_strtoupper(mb_substr($disabledLabel, 0, 1)) . mb_substr($disabledLabel, 1) . ' · ' . $cycle, ''];
    }
    if ($subscription['one_time']) {
        return [translate('lifetime', $i18n) . ' · ' . sprintf(translate('paid_on', $i18n), formatDate($subscription['next_payment_raw'], $lang)), ''];
    }
    $isOverdue = $subscription['auto_renew'] != 1 && $subscription['next_payment_raw'] < date('Y-m-d');
    if ($isOverdue) {
        return [translate('overdue', $i18n) . ' · ' . sprintf(translate('was_due', $i18n), formatDate($subscription['next_payment_raw'], $lang)), 'danger'];
    }
    return [$cycle . ' · ' . formatDate($subscription['next_payment_raw'], $lang), ''];
}

/*
  Prints the subscriptions as grouped lists. With the default sorts the groups
  are Active / Lifetime purchases / Disabled; sorting by category, member or
  payment method groups by that instead (lifetime purchases still go last).
*/
function printSubscriptions($subscriptions, $sort, $categories, $members, $i18n, $settings, $currencies, $mainCurrencyId, $lang)
{
    if ($sort === "price") {
        usort($subscriptions, function ($a, $b) {
            return ($b['sort_price'] ?? $b['price']) <=> ($a['sort_price'] ?? $a['price']);
        });
    }

    $groupByField = in_array($sort, ['category_id', 'payer_user_id', 'payment_method_id'], true);
    $groups = [];
    foreach ($subscriptions as $subscription) {
        if ($subscription['one_time'] && !$subscription['inactive']) {
            $key = 'lifetime';
            $title = translate('lifetime_purchases', $i18n);
        } elseif ($groupByField) {
            $key = $sort . '-' . $subscription[$sort];
            if ($sort === 'category_id') {
                $title = $subscription['category_id'] == 1 ? translate('no_category', $i18n) : ($categories[$subscription['category_id']]['name'] ?? '');
            } elseif ($sort === 'payer_user_id') {
                $title = $members[$subscription['payer_user_id']]['name'] ?? '';
            } else {
                $title = $subscription['payment_method_name'];
            }
        } elseif ($subscription['inactive']) {
            $key = 'disabled';
            $title = translate('disabled', $i18n);
        } else {
            $key = 'active';
            $title = translate('active', $i18n);
        }
        if (!isset($groups[$key])) {
            $groups[$key] = ['title' => $title, 'rows' => []];
        }
        $groups[$key]['rows'][] = $subscription;
    }

    // Fixed order for the state groups; field groups keep the sorted order.
    $order = ['active' => 0, 'disabled' => 2, 'lifetime' => 3];
    uksort($groups, function ($a, $b) use ($order, $groups) {
        $orderA = $order[$a] ?? 1;
        $orderB = $order[$b] ?? 1;
        if ($orderA === $orderB) {
            return array_search($a, array_keys($groups)) - array_search($b, array_keys($groups));
        }
        return $orderA - $orderB;
    });

    $showProgress = ($settings['showSubscriptionProgress'] ?? 'false') === 'true';
    $mainCurrencyCode = $currencies[$mainCurrencyId]['code'] ?? 'EUR';

    foreach ($groups as $key => $group) {
        ?>
        <section class="group" data-group="<?= htmlspecialchars($key) ?>">
            <h2 class="group-title"><?= htmlspecialchars(htmlspecialchars_decode($group['title'])) ?></h2>
            <div class="group-list">
                <?php
                foreach ($group['rows'] as $subscription) {
                    [$subtitle, $tone] = listRowSubtitle($subscription, $i18n, $lang);
                    $trailingSub = '';
                    if (isset($subscription['original_price']) && $subscription['original_price'] != $subscription['price']) {
                        $trailingSub = formatPrice($subscription['original_price'], $subscription['original_currency_code'], $currencies);
                    }
                    echo render_list_row([
                        'id' => $subscription['id'],
                        'name' => $subscription['name'],
                        'logo' => $subscription['logo'],
                        'logo_variant' => $subscription['logo_variant'] ?? null,
                        'logo_text_color' => $subscription['logo_text_color'] ?? null,
                        'subtitle' => $subtitle,
                        'subtitle_tone' => $tone,
                        'trailing' => formatPrice($subscription['price'], $subscription['currency_code'], $currencies),
                        'trailing_sub' => $trailingSub,
                        'dim' => (bool) $subscription['inactive'],
                        'progress' => ($showProgress && !$subscription['inactive'] && !$subscription['one_time']) ? min(100, $subscription['progress']) : null,
                    ]);
                }
                ?>
            </div>
            <?php
            $footer = '';
            if ($key === 'active') {
                $monthlyTotal = array_sum(array_column($group['rows'], 'monthly_main'));
                $footer = sprintf(
                    translate('active_footer', $i18n),
                    count($group['rows']),
                    CurrencyFormatter::format($monthlyTotal, $mainCurrencyCode)
                );
            } elseif ($key === 'lifetime') {
                $footer = translate('lifetime_footer', $i18n);
            } elseif ($key === 'disabled') {
                $footer = translate('disabled_footer', $i18n);
            }
            if ($footer !== '') {
                ?>
                <p class="group-footer"><?= htmlspecialchars($footer) ?></p>
                <?php
            }
            ?>
        </section>
        <?php
    }
}

$query = "SELECT main_currency FROM user WHERE id = :userId";
$stmt = $db->prepare($query);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
$row = $result->fetchArray(SQLITE3_ASSOC);
if ($row !== false) {
    $mainCurrencyId = $row['main_currency'];
} else {
    $mainCurrencyId = $currencies[1]['id'];
}

?>