<?php
/*
This API Endpoint accepts both POST and GET requests.
It receives the following parameters:
- month: the month for which the cost is to be calculated (integer).
- year: the year for which the cost is to be calculated (integer).
- api_key: the API key of the user (string).
- mode (optional): what the cost counts (string).
    cashflow      (default) every payment that falls in the month.
    amortized     every active subscription spread evenly per month (a
                  yearly 120 counts as 10).
    short_cycles  as amortized, but only cycles of a month or shorter.

It returns a JSON object with the following properties:
- success: whether the request was successful (boolean).
- title: a string with "${month} ${year}" (e.g., "March 2025").
- monthly_cost: a float with the total cost for the given month.
- mode: the mode the cost was calculated with.
- localized_monthly_cost: a string with the total cost formatted according to the user's locale and currency.
- currency_code: a string with the currency code of the user's main currency.
- currency_symbol: a string with the currency symbol of the user's main currency.
- notes: warning messages or additional information (array).

Example response:
{
  "success": true,
  "title": "March 2025",
  "monthly_cost": "120.24",
  "localized_monthly_cost": "€120.24",
  "currency_code": "EUR",
  "currency_symbol": "€",
  "notes": []
}
*/

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/recurrence.php';
require_once '../../includes/intro_pricing.php';
require_once '../../includes/monthly_cost.php';
require_once '../../includes/bundles.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER["REQUEST_METHOD"] === "POST" || $_SERVER["REQUEST_METHOD"] === "GET") {
    // if the parameters are not set, return an error

    $apiKey = $_REQUEST['api_key'] ?? $_REQUEST['apiKey'] ?? null;

    if (!$apiKey || !isset($_REQUEST['month']) || !isset($_REQUEST['year'])) {
        $response = [
            "success" => false,
            "title" => "Missing parameters"
        ];
        echo json_encode($response);
        exit;
    }

    $month = $_REQUEST['month'];
    $year = $_REQUEST['year'];
    $mode = $_REQUEST['mode'] ?? 'cashflow';
    if (!in_array($mode, WALLOS_MONTHLY_COST_MODES, true)) {
        echo json_encode([
            "success" => false,
            "title" => "Invalid mode",
            "notes" => ["mode must be one of: " . implode(', ', WALLOS_MONTHLY_COST_MODES) . "."]
        ]);
        exit;
    }

    $sql = "SELECT * FROM user WHERE api_key = :apiKey";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':apiKey', $apiKey);
    $result = $stmt->execute();
    $user = $result->fetchArray(SQLITE3_ASSOC);
    // If the user is not found or the API key is invalid, return an error
    if (!$user) {
        echo json_encode([
            "success" => false,
            "title" => "Invalid API key",
            "notes" => ["User not found or API key invalid."]
        ]);
        exit;
    }

    $sql = "SELECT * FROM last_exchange_update";
    $result = $db->query($sql);
    $lastExchangeUpdate = $result->fetchArray(SQLITE3_ASSOC);

    $userId = $user['id'];
    $userCurrencyId = $user['main_currency'];
    $needsCurrencyConversion = false;
    $canConvertCurrency = empty($lastExchangeUpdate['date']) ? false : true;

    $sql = "SELECT * FROM currencies WHERE id = :currencyId";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':currencyId', $userCurrencyId);
    $result = $stmt->execute();
    $currency = $result->fetchArray(SQLITE3_ASSOC);
    $currency_code = $currency['code'];
    $currency_symbol = $currency['symbol'];


    $title = date('F Y', strtotime($year . '-' . $month . '-01'));
    $monthlyCost = 0;
    $notes = [];
    $currencies = [];

    // Subscriptions included in a parent bill are paid through it (issue #24).
    $sql = "SELECT * FROM subscriptions WHERE user_id = :userId AND inactive = 0 AND " . wallos_standalone_sql();
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':userId', $userId);
    $result = $stmt->execute();
    $subscriptions = [];
    while ($subscription = $result->fetchArray(SQLITE3_ASSOC)) {
        $subscriptions[] = $subscription;
        if ($subscription['currency_id'] !== $userCurrencyId) {
            $needsCurrencyConversion = true;
        }
    }

    if ($needsCurrencyConversion) {
        if (!$canConvertCurrency) {
            $notes[] = "You are using multiple currencies, but the exchange rates have not been updated yet. Please check your Fixer API Key.";
        } else {
            $sql = "SELECT * FROM currencies WHERE user_id = :userId";
            $stmt = $db->prepare($sql);
            $stmt->bindValue(':userId', $userId);
            $result = $stmt->execute();
            while ($currency = $result->fetchArray(SQLITE3_ASSOC)) {
                $currencies[$currency['id']] = $currency['rate'];
            }
        }
    }

    // Every payment date of each subscription inside the requested month,
    // from the shared recurrence engine (calendar-anchored months, start
    // date, end of a fixed-term plan).
    $monthStart = new DateTime(sprintf('%04d-%02d-01', $year, $month));
    $monthEnd = (clone $monthStart)->modify('last day of this month');
    foreach ($subscriptions as $subscription) {
        if ($mode === 'cashflow') {
            $dates = wallos_occurrences_in_range($subscription, $monthStart, $monthEnd);
            if ($dates === []) {
                continue;
            }
            // Each payment at its own price (an intro price during a trial or promo).
            $price = wallos_price_of_occurrences($subscription, $dates);
        } else {
            // Spread evenly, at the price that applies at the start of the month.
            if ((int) $subscription['cycle'] === 5) {
                continue;
            }
            if ($mode === 'short_cycles' && !wallos_is_short_cycle($subscription['cycle'], $subscription['frequency'])) {
                continue;
            }
            $price = wallos_price_per_month($subscription['cycle'], $subscription['frequency'], wallos_price_on($subscription, $monthStart->format('Y-m-d')), wallos_cycle_active_months($subscription));
        }
        if (
            $userCurrencyId !== $subscription['currency_id']
            && $canConvertCurrency
            && isset($currencies[$userCurrencyId], $currencies[$subscription['currency_id']])
            && (float)$currencies[$subscription['currency_id']] !== 0.0
        ) {
            $price *= $currencies[$userCurrencyId] / $currencies[$subscription['currency_id']];
        }
        $monthlyCost += $price;
    }

    $formatter = new NumberFormatter('en_US', NumberFormatter::CURRENCY);
    $localizedMonthlyCost = $formatter->formatCurrency($monthlyCost, $currency_code);

    echo json_encode([
        'success' => true,
        'title' => $title,
        'monthly_cost' => number_format($monthlyCost, 2),
        'mode' => $mode,
        'localized_monthly_cost' => $localizedMonthlyCost,
        'currency_code' => $currency_code,
        'currency_symbol' => $currency_symbol,
        'notes' => $notes
    ], JSON_UNESCAPED_UNICODE);

}
?>
