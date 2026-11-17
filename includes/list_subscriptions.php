<?php

require_once 'i18n/getlang.php';
require_once __DIR__ . '/currency_rates.php';
require_once __DIR__ . '/ui_helpers.php';
require_once __DIR__ . '/payment_ledger.php';
require_once __DIR__ . '/cancellation_deadline.php';

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

function getSubscriptionProgress($cycle, $frequency, $next_payment, $subscription = [])
{
    // Measured against the real calendar cycle that contains today (see
    // wallos_cycle_progress()), not a 30- or 365-day approximation.
    $subscription = array_merge($subscription, [
        'cycle' => (int) $cycle,
        'frequency' => (int) $frequency,
        'next_payment' => $next_payment,
    ]);

    return wallos_cycle_progress($subscription, new DateTime('today'));
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

    // Schedule state: fixed-term plans, variable amounts and the paid badge.
    static $paidMaps = [];
    $mapKey = spl_object_id($db) . '-' . (int) ($subscription['user_id'] ?? 0);
    if (!isset($paidMaps[$mapKey])) {
        $paidMaps[$mapKey] = wallos_ledger_last_paid_map($db, (int) ($subscription['user_id'] ?? 0));
    }
    $printRow['paid_badge'] = wallos_paid_badge_date($subscription, $paidMaps[$mapKey][(int) $subscription['id']] ?? null);
    $printRow['ended'] = ($subscription['ended_reason'] ?? null) === 'completed';
    $printRow['variable_amount'] = (int) ($subscription['variable_amount'] ?? 0) === 1;
    $printRow['end_date'] = $subscription['end_date'] ?? null;
    $printRow['plan_total'] = isset($subscription['total_payments']) ? (int) $subscription['total_payments'] : null;
    $printRow['plan_made'] = (int) ($subscription['payments_made'] ?? 0);
    $deadline = wallos_cancel_deadline($subscription);
    $printRow['cancel_by'] = ($deadline !== null && $deadline['cancel_by'] >= date('Y-m-d')) ? $deadline['cancel_by'] : null;
    $printRow['locked_until'] = wallos_cancel_locked_until($subscription);
    if ($printRow['plan_total']) {
        $printRow['progress'] = (int) floor(min($printRow['plan_made'], $printRow['plan_total']) / $printRow['plan_total'] * 100);
    } else {
        $printRow['progress'] = getSubscriptionProgress((int) $subscription['cycle'], (int) $subscription['frequency'], $subscription['next_payment'], $subscription);
    }
}

/**
 * "Payment 4 of 12 · ends Mar 2027" for a fixed-term plan, or '' for an open-ended subscription.
 */
function listRowPlanText($subscription, $i18n, $lang)
{
    if (empty($subscription['plan_total'])) {
        return '';
    }
    $current = min($subscription['plan_made'] + 1, $subscription['plan_total']);
    $text = sprintf(translate('payment_n_of_total', $i18n), $current, $subscription['plan_total']);
    if (!empty($subscription['end_date'])) {
        $text .= ' · ' . sprintf(translate('ends_on', $i18n), formatDate($subscription['end_date'], $lang));
    }

    return $text;
}

function listRowSubtitle($subscription, $i18n, $lang)
{
    $cycle = $subscription['billing_cycle'];
    if (!empty($subscription['ended'])) {
        $total = (int) ($subscription['plan_total'] ?? 0);
        return [translate('plan_completed_label', $i18n) . ($total ? ' · ' . sprintf(translate('n_payments', $i18n), $total) : ''), ''];
    }
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
    $planText = listRowPlanText($subscription, $i18n, $lang);
    $schedule = $planText !== '' ? formatDate($subscription['next_payment_raw'], $lang) . ' · ' . $planText
        : $cycle . ' · ' . formatDate($subscription['next_payment_raw'], $lang);
    // Cancellation notice: locked in by a minimum term, or the date to cancel by.
    if (!empty($subscription['locked_until'])) {
        $schedule .= ' · ' . sprintf(translate('locked_in_until', $i18n), formatDate($subscription['locked_until'], $lang));
    } elseif (!empty($subscription['cancel_by'])) {
        $schedule .= ' · ' . sprintf(translate('cancel_by', $i18n), formatDate($subscription['cancel_by'], $lang));
        $daysLeft = (int) (new DateTime('today'))->diff(new DateTime($subscription['cancel_by']))->format('%r%a');
        if ($daysLeft <= 14 && empty($subscription['paid_badge'])) {
            return [$schedule, 'warning'];
        }
    }
    if (!empty($subscription['paid_badge'])) {
        return [sprintf(translate('paid_badge', $i18n), formatDate($subscription['paid_badge'], $lang)) . ' · ' . $schedule, 'success'];
    }
    return [$schedule, ''];
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
        } elseif (!empty($subscription['ended']) && !$groupByField) {
            $key = 'completed';
            $title = translate('completed_plans', $i18n);
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
    $order = ['active' => 0, 'completed' => 2, 'disabled' => 3, 'lifetime' => 4];
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
                        'trailing' => (!empty($subscription['variable_amount']) ? '≈ ' : '') . formatPrice($subscription['price'], $subscription['currency_code'], $currencies),
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
            } elseif ($key === 'completed') {
                $footer = translate('completed_footer', $i18n);
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
