<?php
/*
  Cost sharing and reimbursements (issue #34).

  subscription_shares (migration 000072)
    subscription_id, household_id (a member from Settings > Household),
    share_percent, user_id. The shares of a subscription add up to 100.
    No rows: the payer owns all of it.

  subscriptions.reimbursed_percent / reimbursed_reason
    Part of the cost that comes back (tax relief, an employer...).

  My cost = price x my share x (1 - reimbursed %). "Me" is the account's
  first household member, the one named after the account.

  Lists, the dashboard and the calendar keep the full price, which is what
  leaves the account. The statistics page switches between Gross and My
  cost (settings.stats_net_cost holds the default), and shows what each
  member owes the payer per month.
*/

const WALLOS_STATS_COST_MODES = ['gross', 'net'];

/**
 * The account holder's own household member: the first one, created with the account.
 *
 * @return int|null
 */
function wallos_self_member_id(SQLite3 $db, $userId)
{
    $stmt = $db->prepare('SELECT MIN(id) FROM household WHERE user_id = :userId');
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result ? $result->fetchArray(SQLITE3_NUM) : false;

    return $row && $row[0] !== null ? (int) $row[0] : null;
}

/**
 * Every share of the account's subscriptions.
 *
 * @return array<int, array<int, float>> subscription id => member id => percent
 */
function wallos_load_subscription_shares(SQLite3 $db, $userId)
{
    $shares = [];
    $stmt = @$db->prepare('SELECT subscription_id, household_id, share_percent FROM subscription_shares WHERE user_id = :userId ORDER BY id');
    if ($stmt === false) {
        return $shares;
    }
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $shares[(int) $row['subscription_id']][(int) $row['household_id']] = (float) $row['share_percent'];
    }

    return $shares;
}

/**
 * Who owns what part of a subscription, as fractions that add up to 1.
 *
 * @param array $subscription needs payer_user_id
 * @param array $shares       member id => percent, from wallos_load_subscription_shares()
 * @param int|null $selfMemberId used when the subscription has no payer
 * @return array<int, float> member id => fraction
 */
function wallos_member_fractions(array $subscription, array $shares, $selfMemberId = null)
{
    if ($shares !== []) {
        $total = array_sum($shares);
        if ($total > 0) {
            return array_map(function ($percent) use ($total) {
                return $percent / $total;
            }, $shares);
        }
    }
    $payer = (int) ($subscription['payer_user_id'] ?? 0);
    if ($payer <= 0) {
        $payer = (int) $selfMemberId;
    }

    return $payer > 0 ? [$payer => 1.0] : [];
}

/**
 * @param mixed $percent
 * @return float 0-100
 */
function wallos_sanitize_reimbursed_percent($percent)
{
    if (!is_numeric($percent)) {
        return 0.0;
    }

    return max(0.0, min(100.0, round((float) $percent, 2)));
}

/**
 * The part of the price that is the account holder's own cost.
 *
 * @return float 0-1
 */
function wallos_my_cost_fraction(array $subscription, array $shares, $selfMemberId)
{
    $fractions = wallos_member_fractions($subscription, $shares, $selfMemberId);
    $mine = $selfMemberId !== null ? ($fractions[(int) $selfMemberId] ?? 0.0) : 0.0;
    $reimbursed = wallos_sanitize_reimbursed_percent($subscription['reimbursed_percent'] ?? 0);

    return $mine * (1 - $reimbursed / 100);
}

/**
 * Whether the account holder's cost differs from the full price.
 *
 * @return bool
 */
function wallos_has_cost_adjustment(array $subscription, array $shares)
{
    return $shares !== [] || wallos_sanitize_reimbursed_percent($subscription['reimbursed_percent'] ?? 0) > 0;
}

/**
 * Monthly amounts each member owes the payer for one subscription.
 *
 * @param float $monthly the subscription's full monthly price
 * @return array<int, float> member id => amount (the payer never owes itself)
 */
function wallos_amounts_owed(array $subscription, array $shares, $monthly)
{
    $owed = [];
    $payer = (int) ($subscription['payer_user_id'] ?? 0);
    if ($shares === [] || $payer <= 0) {
        return $owed;
    }
    foreach (wallos_member_fractions($subscription, $shares) as $memberId => $fraction) {
        if ($memberId !== $payer && $fraction > 0) {
            $owed[$memberId] = $monthly * $fraction;
        }
    }

    return $owed;
}

/**
 * Validates the shares the form or the API sent.
 *
 * @param mixed $input        member id => percent (empty values are skipped)
 * @param array $memberIds    the account's household member ids
 * @return array{shares: array<int, float>, error: ?string}
 */
function wallos_shares_from_input($input, array $memberIds)
{
    $shares = [];
    if (!is_array($input)) {
        return ['shares' => [], 'error' => null];
    }
    $allowed = array_flip(array_map('intval', $memberIds));
    foreach ($input as $memberId => $percent) {
        if ($percent === '' || $percent === null) {
            continue;
        }
        if (!ctype_digit((string) $memberId) || !isset($allowed[(int) $memberId])) {
            return ['shares' => [], 'error' => 'Unknown household member.'];
        }
        if (!is_numeric($percent) || (float) $percent < 0 || (float) $percent > 100) {
            return ['shares' => [], 'error' => 'Each share must be a percentage from 0 to 100.'];
        }
        if ((float) $percent > 0) {
            $shares[(int) $memberId] = round((float) $percent, 4);
        }
    }
    if ($shares === []) {
        return ['shares' => [], 'error' => null];
    }
    if (abs(array_sum($shares) - 100) > 0.05) {
        return ['shares' => [], 'error' => 'The shares must add up to 100%.'];
    }
    return ['shares' => $shares, 'error' => null];
}

/**
 * Replaces a subscription's shares.
 *
 * @return bool
 */
function wallos_store_subscription_shares(SQLite3 $db, $subscriptionId, $userId, array $shares)
{
    $delete = $db->prepare('DELETE FROM subscription_shares WHERE subscription_id = :id AND user_id = :userId');
    $delete->bindValue(':id', (int) $subscriptionId, SQLITE3_INTEGER);
    $delete->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
    if ($delete->execute() === false) {
        return false;
    }
    foreach ($shares as $memberId => $percent) {
        $insert = $db->prepare('INSERT INTO subscription_shares (subscription_id, household_id, share_percent, user_id)
            VALUES (:id, :member, :percent, :userId)');
        $insert->bindValue(':id', (int) $subscriptionId, SQLITE3_INTEGER);
        $insert->bindValue(':member', (int) $memberId, SQLITE3_INTEGER);
        $insert->bindValue(':percent', (float) $percent, SQLITE3_FLOAT);
        $insert->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
        if ($insert->execute() === false) {
            return false;
        }
    }

    return true;
}

/**
 * @return string 'gross' or 'net'
 */
function wallos_stats_cost_mode($requested, $defaultNet)
{
    if (is_string($requested) && in_array($requested, WALLOS_STATS_COST_MODES, true)) {
        return $requested;
    }

    return !empty($defaultNet) ? 'net' : 'gross';
}

/**
 * Scales each row's prices to the account holder's own cost, for the
 * statistics page's "My cost" view. Rows other accounts share with the
 * household are left as they are. Each row keeps its full prices in
 * gross_price / gross_intro_price.
 *
 * @param array[] $subscriptions
 * @return array[]
 */
function wallos_apply_my_cost(array $subscriptions, array $sharesById, $selfMemberId)
{
    foreach ($subscriptions as &$row) {
        if (!empty($row['shared_owner_name'])) {
            continue;
        }
        $fraction = wallos_my_cost_fraction($row, $sharesById[(int) $row['id']] ?? [], $selfMemberId);
        $row['gross_price'] = $row['price'];
        $row['gross_intro_price'] = $row['intro_price'] ?? null;
        $row['price'] = (float) $row['price'] * $fraction;
        if (isset($row['intro_price']) && $row['intro_price'] !== null) {
            $row['intro_price'] = (float) $row['intro_price'] * $fraction;
        }
    }
    unset($row);

    return $subscriptions;
}

/**
 * The row with its full prices back (see wallos_apply_my_cost()).
 *
 * @return array
 */
function wallos_gross_row(array $row)
{
    if (!array_key_exists('gross_price', $row)) {
        return $row;
    }

    return array_merge($row, ['price' => $row['gross_price'], 'intro_price' => $row['gross_intro_price']]);
}

/**
 * The account's shares and own member, loaded once per request.
 *
 * @return array{0: array, 1: int|null}
 */
function wallos_cost_sharing_context(SQLite3 $db, $userId)
{
    static $cache = [];
    $userId = (int) $userId;
    if (!isset($cache[$userId])) {
        $cache[$userId] = [wallos_load_subscription_shares($db, $userId), wallos_self_member_id($db, $userId)];
    }

    return $cache[$userId];
}
