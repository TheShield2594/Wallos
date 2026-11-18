<?php
/*
  Shared households: several Tally accounts on one instance see each other's
  shared subscriptions.

  households         id, name, owner_user_id
  household_members  household_id, user_id, role ('viewer' | 'editor'),
                     status ('pending' until the invite is accepted, 'active')
  subscriptions.shared_household_id
                     NULL: private (the default). Otherwise the household the
                     owner shares it with; only the owner sets it.

  An account belongs to at most one household at a time, as its owner or as
  an active member. Pending invites grant nothing.

  Access to someone else's subscription (wallos_subscription_access()):
    - it must be shared with the household the viewer is active in, and its
      owner must still be in that household;
    - viewers read it; editors and the household owner may also edit it and
      mark it as paid. Deleting, cloning and changing who it is shared with
      stay with the subscription's owner.

  Every page and endpoint that reads or writes a subscription by id asks
  this file, so the rule lives in one place (tests/cases/households_test.php).

  Payer labels (the per-account `household` table, "Paid by") can be linked
  to a real account in the same shared household (household.linked_user_id,
  issue #59). Shared rows then show that account's name as the payer, and
  the linked account gets the subscription's reminders too. A link only
  counts while both accounts are in the same household, and is cleared when
  either leaves it.

  Shared subscriptions keep their owner's category, payer and payment method,
  shown read-only by name. Their price is shown in the viewer's currency with
  the same code as the owner's, converted to the viewer's main currency when
  the viewer has no such currency.
*/

require_once __DIR__ . '/currency_rates.php';

const WALLOS_HOUSEHOLD_ROLES = ['viewer', 'editor'];

/*
  Shared rows keep their owner's category, payer and payment method, which
  are not the viewer's records. Breakdowns and filters put them in one
  "Shared with me" bucket under this id (issue #59), which no row of the
  viewer's own can have.
*/
const WALLOS_SHARED_BUCKET_ID = 2147483647;

/**
 * The household an account is active in.
 *
 * @return array|null ['id', 'name', 'owner_user_id', 'role' => 'owner'|'editor'|'viewer']
 */
function wallos_household_membership(SQLite3 $db, $userId)
{
    $stmt = $db->prepare("SELECT h.id, h.name, h.owner_user_id, 'owner' AS role FROM households h WHERE h.owner_user_id = :userId
        UNION ALL
        SELECT h.id, h.name, h.owner_user_id, m.role FROM households h
            JOIN household_members m ON m.household_id = h.id
            WHERE m.user_id = :userId AND m.status = 'active'
        LIMIT 1");
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;

    return $row === false ? null : $row;
}

/**
 * Invites waiting for an answer.
 *
 * @return array[] ['household_id', 'name', 'owner_name', 'role']
 */
function wallos_household_pending_invites(SQLite3 $db, $userId)
{
    $stmt = $db->prepare("SELECT h.id AS household_id, h.name, u.username AS owner_name, m.role
        FROM household_members m
        JOIN households h ON h.id = m.household_id
        JOIN user u ON u.id = h.owner_user_id
        WHERE m.user_id = :userId AND m.status = 'pending'
        ORDER BY h.name");
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $invites = [];
    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $invites[] = $row;
    }

    return $invites;
}

/**
 * The owner and the members (active and invited) of a household.
 *
 * @return array[] ['user_id', 'username', 'role', 'status']
 */
function wallos_household_members(SQLite3 $db, $householdId)
{
    $stmt = $db->prepare("SELECT h.owner_user_id AS user_id, u.username, 'owner' AS role, 'active' AS status, 0 AS sort
            FROM households h JOIN user u ON u.id = h.owner_user_id WHERE h.id = :householdId
        UNION ALL
        SELECT m.user_id, u.username, m.role, m.status, 1 AS sort
            FROM household_members m JOIN user u ON u.id = m.user_id WHERE m.household_id = :householdId
        ORDER BY sort, username");
    $stmt->bindValue(':householdId', (int) $householdId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $members = [];
    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        unset($row['sort']);
        $members[] = $row;
    }

    return $members;
}

/**
 * What an account may do with a subscription.
 *
 * @return string|null 'owner', 'editor', 'viewer', or null for no access
 */
function wallos_subscription_access(SQLite3 $db, $userId, $subscriptionId)
{
    $stmt = $db->prepare('SELECT user_id, shared_household_id FROM subscriptions WHERE id = :id');
    $stmt->bindValue(':id', (int) $subscriptionId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
    if ($row === false) {
        return null;
    }

    return wallos_subscription_access_for_row($db, $userId, $row);
}

/**
 * @see wallos_subscription_access() for a row already loaded (needs user_id
 *      and shared_household_id)
 */
function wallos_subscription_access_for_row(SQLite3 $db, $userId, array $row)
{
    if ((int) $row['user_id'] === (int) $userId) {
        return 'owner';
    }
    $householdId = $row['shared_household_id'] ?? null;
    if ($householdId === null || $householdId === '') {
        return null;
    }
    $membership = wallos_household_membership($db, $userId);
    if ($membership === null || (int) $membership['id'] !== (int) $householdId) {
        return null;
    }
    // The subscription's owner must still be in the household.
    $ownerMembership = wallos_household_membership($db, (int) $row['user_id']);
    if ($ownerMembership === null || (int) $ownerMembership['id'] !== (int) $householdId) {
        return null;
    }

    return $membership['role'] === 'viewer' ? 'viewer' : 'editor';
}

/**
 * Loads a subscription the account may read (or edit).
 *
 * @param string $need 'read' or 'edit'
 * @return array|false the subscriptions row, with 'access' added
 */
function wallos_load_accessible_subscription(SQLite3 $db, $userId, $subscriptionId, $need = 'read')
{
    $stmt = $db->prepare('SELECT * FROM subscriptions WHERE id = :id');
    $stmt->bindValue(':id', (int) $subscriptionId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
    if ($row === false) {
        return false;
    }
    $access = wallos_subscription_access_for_row($db, $userId, $row);
    if ($access === null || ($need === 'edit' && $access === 'viewer')) {
        return false;
    }
    $row['access'] = $access;

    return $row;
}

/**
 * Ids of every subscription an account can see: its own and the ones shared
 * with its household.
 *
 * @return int[]
 */
function wallos_visible_subscription_ids(SQLite3 $db, $userId)
{
    $ids = [];
    $stmt = $db->prepare('SELECT id FROM subscriptions WHERE user_id = :userId');
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $ids[] = (int) $row['id'];
    }
    foreach (wallos_shared_subscriptions($db, $userId) as $row) {
        $ids[] = (int) $row['id'];
    }
    sort($ids);

    return $ids;
}

/**
 * Other members' subscriptions shared with the account's household, ready to
 * sit next to its own: the currency is mapped onto the viewer's currencies
 * and the owner's names are attached.
 *
 * Added keys: shared_owner_id, shared_owner_name, access ('editor'|'viewer'),
 * shared_category_name, shared_payer_name, shared_payment_method_name,
 * shared_currency_code, shared_original_price.
 *
 * @param bool $activeOnly only active subscriptions
 * @return array[]
 */
function wallos_shared_subscriptions(SQLite3 $db, $userId, $activeOnly = false)
{
    $membership = wallos_household_membership($db, $userId);
    if ($membership === null) {
        return [];
    }
    $access = $membership['role'] === 'viewer' ? 'viewer' : 'editor';

    $stmt = $db->prepare("SELECT s.*, u.username AS shared_owner_name,
            c.name AS shared_category_name, hh.name AS shared_payer_name, pm.name AS shared_payment_method_name,
            cur.code AS shared_currency_code, cur.rate AS shared_currency_rate,
            hh.linked_user_id AS shared_payer_linked_user_id, lu.username AS shared_payer_linked_username
        FROM subscriptions s
        JOIN user u ON u.id = s.user_id
        LEFT JOIN categories c ON c.id = s.category_id AND c.user_id = s.user_id
        LEFT JOIN household hh ON hh.id = s.payer_user_id AND hh.user_id = s.user_id
        LEFT JOIN user lu ON lu.id = hh.linked_user_id
        LEFT JOIN payment_methods pm ON pm.id = s.payment_method_id
        LEFT JOIN currencies cur ON cur.id = s.currency_id AND cur.user_id = s.user_id
        WHERE s.shared_household_id = :householdId
          AND s.user_id != :userId
          AND (s.user_id = :ownerId OR EXISTS (
                SELECT 1 FROM household_members m
                WHERE m.household_id = :householdId AND m.user_id = s.user_id AND m.status = 'active'))
          " . ($activeOnly ? 'AND s.inactive = 0' : '') . "
        ORDER BY s.next_payment ASC");
    $stmt->bindValue(':householdId', (int) $membership['id'], SQLITE3_INTEGER);
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':ownerId', (int) $membership['owner_user_id'], SQLITE3_INTEGER);
    $result = $stmt->execute();

    $viewerCurrencies = wallos_household_viewer_currencies($db, $userId);
    $inHousehold = array_flip(wallos_household_account_ids($db, (int) $membership['id']));
    $rows = [];
    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        // A payer label linked to an account of this household shows that
        // account's name; a stale link (the account left) is ignored.
        $linkedId = $row['shared_payer_linked_user_id'] === null ? null : (int) $row['shared_payer_linked_user_id'];
        if ($linkedId !== null && isset($inHousehold[$linkedId]) && $row['shared_payer_linked_username'] !== null) {
            $row['shared_payer_name'] = $row['shared_payer_linked_username'];
        } else {
            $linkedId = null;
        }
        $row['shared_payer_linked_user_id'] = $linkedId;
        unset($row['shared_payer_linked_username']);
        $row['shared_owner_id'] = (int) $row['user_id'];
        $row['access'] = $access;
        $row['shared_original_price'] = (float) $row['price'];
        $localized = wallos_household_localize_currency($db, $row, $viewerCurrencies, $userId);
        if ($localized !== null) {
            $rows[] = $localized;
        }
    }

    return $rows;
}

/**
 * @return array ['by_code' => code => id, 'rate_by_code' => code => rate, 'main_id' => int, 'main_code' => string]
 */
function wallos_household_viewer_currencies(SQLite3 $db, $userId)
{
    $byCode = [];
    $rateByCode = [];
    $stmt = $db->prepare('SELECT id, code, rate FROM currencies WHERE user_id = :userId');
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $byCode[$row['code']] = (int) $row['id'];
        $rateByCode[$row['code']] = (float) $row['rate'];
    }
    $stmt = $db->prepare('SELECT u.main_currency, c.code FROM user u LEFT JOIN currencies c ON c.id = u.main_currency WHERE u.id = :userId');
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $main = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;

    return ['by_code' => $byCode, 'rate_by_code' => $rateByCode, 'main_id' => (int) ($main['main_currency'] ?? 0), 'main_code' => $main['code'] ?? null];
}

/**
 * Where an amount in one of the owner's currencies lands for a viewer.
 *
 *   - The viewer has a currency of the same code: that one, unchanged.
 *   - Otherwise the amount is converted, with the owner's rates, into the
 *     owner's main currency; when the viewer has that code it is shown there.
 *   - Otherwise it goes on, through a currency code both accounts hold, into
 *     the viewer's main currency (rates are units per main currency, all from
 *     the same exchange-rate source).
 *
 * @return array{currency_id: int, factor: float}|null null when there is no
 *         conversion path: the row is then left out rather than mislabelled
 */
function wallos_household_currency_mapping(SQLite3 $db, $viewerId, $ownerCurrencyId, ?array $viewerCurrencies = null)
{
    $viewerCurrencies = $viewerCurrencies ?? wallos_household_viewer_currencies($db, $viewerId);
    $stmt = $db->prepare('SELECT code, rate, user_id FROM currencies WHERE id = :id');
    $stmt->bindValue(':id', (int) $ownerCurrencyId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $currency = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
    if ($currency === false) {
        return null;
    }
    if (isset($viewerCurrencies['by_code'][$currency['code']])) {
        return ['currency_id' => $viewerCurrencies['by_code'][$currency['code']], 'factor' => 1.0];
    }
    $rate = (float) $currency['rate'];
    if ($rate <= 0) {
        return null;
    }

    // The owner's rates by code, and their main currency's code.
    $ownerRates = [];
    $stmt = $db->prepare('SELECT code, rate FROM currencies WHERE user_id = :ownerId');
    $stmt->bindValue(':ownerId', (int) $currency['user_id'], SQLITE3_INTEGER);
    $result = $stmt->execute();
    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $ownerRates[$row['code']] = (float) $row['rate'];
    }
    $stmt = $db->prepare('SELECT c.code FROM user u JOIN currencies c ON c.id = u.main_currency WHERE u.id = :ownerId');
    $stmt->bindValue(':ownerId', (int) $currency['user_id'], SQLITE3_INTEGER);
    $result = $stmt->execute();
    $ownerMain = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
    $ownerMainCode = $ownerMain['code'] ?? null;

    $toOwnerMain = 1 / $rate;
    if ($ownerMainCode !== null && isset($viewerCurrencies['by_code'][$ownerMainCode])) {
        return ['currency_id' => $viewerCurrencies['by_code'][$ownerMainCode], 'factor' => $toOwnerMain];
    }

    // Through a code both hold, the viewer's main currency first.
    $candidates = array_unique(array_filter(array_merge([$viewerCurrencies['main_code']], array_keys($viewerCurrencies['rate_by_code']))));
    foreach ($candidates as $code) {
        $ownerRate = $ownerRates[$code] ?? 0;
        $viewerRate = $viewerCurrencies['rate_by_code'][$code] ?? 0;
        if ($ownerRate > 0 && $viewerRate > 0) {
            return ['currency_id' => $viewerCurrencies['main_id'], 'factor' => $toOwnerMain * $ownerRate / $viewerRate];
        }
    }

    return null;
}

/**
 * Shows a shared row in the viewer's currencies (see
 * wallos_household_currency_mapping()).
 *
 * @return array|null null when its amount cannot be expressed for the viewer
 */
function wallos_household_localize_currency(SQLite3 $db, array $row, array $viewerCurrencies, $viewerId = null)
{
    $mapping = wallos_household_currency_mapping($db, $viewerId, $row['currency_id'], $viewerCurrencies);
    if ($mapping === null) {
        return null;
    }
    foreach (['price', 'intro_price'] as $priceColumn) {
        if (isset($row[$priceColumn]) && $row[$priceColumn] !== null) {
            $row[$priceColumn] = (float) $row[$priceColumn] * $mapping['factor'];
        }
    }
    $row['currency_id'] = $mapping['currency_id'];

    return $row;
}

/**
 * How an amount in the owner's currency is shown to a viewer: the factor of
 * wallos_household_currency_mapping() (1.0 for the same code). Dividing by it
 * turns an amount the viewer entered back into the owner's currency.
 *
 * @return float|null null when there is no conversion path: callers refuse
 *                    the request rather than store or show a wrong amount
 */
function wallos_household_display_factor(SQLite3 $db, $viewerId, $ownerCurrencyId)
{
    $mapping = wallos_household_currency_mapping($db, $viewerId, $ownerCurrencyId);

    return $mapping === null || $mapping['factor'] <= 0 ? null : $mapping['factor'];
}

/**
 * @return array{success: bool, error?: string, household_id?: int}
 */
function wallos_household_create(SQLite3 $db, $userId, $name)
{
    $name = trim((string) $name);
    if ($name === '' || mb_strlen($name) > 80) {
        return ['success' => false, 'error' => 'invalid_name'];
    }
    // One statement checks and creates, inside one transaction with voiding
    // the owner's pending invites: an invite accepted at the same moment
    // cannot make the account a member and an owner at once (the accept
    // refuses an account that owns a household; see wallos_household_respond()).
    $outcome = ['success' => false, 'error' => 'write_failed'];
    $committed = wallos_household_transaction($db, function () use ($db, $userId, $name, &$outcome) {
        $stmt = $db->prepare("INSERT INTO households (name, owner_user_id)
            SELECT :name, :ownerId
            WHERE NOT EXISTS (SELECT 1 FROM households WHERE owner_user_id = :ownerId)
              AND NOT EXISTS (SELECT 1 FROM household_members WHERE user_id = :ownerId AND status = 'active')");
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->bindValue(':ownerId', (int) $userId, SQLITE3_INTEGER);
        if ($stmt->execute() === false) {
            return false;
        }
        if ($db->changes() === 0) {
            $outcome = ['success' => false, 'error' => 'already_in_household'];
            return false;
        }
        $householdId = $db->lastInsertRowID();
        // Invites the owner had pending are void now.
        $stmt = $db->prepare("DELETE FROM household_members WHERE user_id = :userId AND status = 'pending'");
        $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
        if (@$stmt->execute() === false) {
            return false;
        }
        $outcome = ['success' => true, 'household_id' => $householdId];

        return true;
    });

    // Success only once the transaction committed; a failed commit rolled
    // the household back.
    if (!$committed && $outcome['success']) {
        return ['success' => false, 'error' => 'write_failed'];
    }

    return $outcome;
}

/**
 * The owner invites another account on the instance by username or email.
 *
 * @return array{success: bool, error?: string}
 */
function wallos_household_invite(SQLite3 $db, $ownerId, $identifier, $role)
{
    $membership = wallos_household_membership($db, $ownerId);
    if ($membership === null || $membership['role'] !== 'owner') {
        return ['success' => false, 'error' => 'not_owner'];
    }
    if (!in_array($role, WALLOS_HOUSEHOLD_ROLES, true)) {
        return ['success' => false, 'error' => 'invalid_role'];
    }
    $identifier = trim((string) $identifier);
    $stmt = $db->prepare('SELECT id FROM user WHERE username = :identifier OR (email = :identifier AND email != \'\') LIMIT 1');
    $stmt->bindValue(':identifier', $identifier, SQLITE3_TEXT);
    $result = $stmt->execute();
    $invitee = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
    if ($identifier === '' || $invitee === false) {
        return ['success' => false, 'error' => 'user_not_found'];
    }
    $inviteeId = (int) $invitee['id'];
    if ($inviteeId === (int) $ownerId) {
        return ['success' => false, 'error' => 'cannot_invite_self'];
    }
    if (wallos_household_membership($db, $inviteeId) !== null) {
        return ['success' => false, 'error' => 'already_in_household'];
    }
    $stmt = $db->prepare("INSERT INTO household_members (household_id, user_id, role, status) VALUES (:householdId, :userId, :role, 'pending')
        ON CONFLICT (household_id, user_id) DO UPDATE SET role = excluded.role");
    $stmt->bindValue(':householdId', (int) $membership['id'], SQLITE3_INTEGER);
    $stmt->bindValue(':userId', $inviteeId, SQLITE3_INTEGER);
    $stmt->bindValue(':role', $role, SQLITE3_TEXT);

    return $stmt->execute() !== false ? ['success' => true] : ['success' => false, 'error' => 'write_failed'];
}

/**
 * Accepts or declines an invite.
 *
 * @return array{success: bool, error?: string}
 */
function wallos_household_respond(SQLite3 $db, $userId, $householdId, $accept)
{
    $stmt = $db->prepare("SELECT 1 FROM household_members WHERE household_id = :householdId AND user_id = :userId AND status = 'pending'");
    $stmt->bindValue(':householdId', (int) $householdId, SQLITE3_INTEGER);
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    if (!$result || $result->fetchArray(SQLITE3_NUM) === false) {
        return ['success' => false, 'error' => 'no_invite'];
    }
    if (!$accept) {
        $stmt = $db->prepare('DELETE FROM household_members WHERE household_id = :householdId AND user_id = :userId');
    } else {
        // One statement checks and activates, so two accepts racing from
        // different sessions cannot both succeed.
        $stmt = $db->prepare("UPDATE household_members SET status = 'active'
            WHERE household_id = :householdId AND user_id = :userId AND status = 'pending'
              AND NOT EXISTS (SELECT 1 FROM household_members WHERE user_id = :userId AND status = 'active')
              AND NOT EXISTS (SELECT 1 FROM households WHERE owner_user_id = :userId)");
    }
    $stmt->bindValue(':householdId', (int) $householdId, SQLITE3_INTEGER);
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
    if ($stmt->execute() === false) {
        return ['success' => false, 'error' => 'write_failed'];
    }
    if ($accept && $db->changes() === 0) {
        return ['success' => false, 'error' => 'already_in_household'];
    }
    if ($accept) {
        // One household at a time: the other invites lapse.
        $stmt = $db->prepare("DELETE FROM household_members WHERE user_id = :userId AND status = 'pending'");
        $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
        if ($stmt->execute() === false) {
            return ['success' => false, 'error' => 'write_failed'];
        }
    }

    return ['success' => true];
}

/**
 * @return array{success: bool, error?: string}
 */
function wallos_household_set_role(SQLite3 $db, $ownerId, $memberUserId, $role)
{
    $membership = wallos_household_membership($db, $ownerId);
    if ($membership === null || $membership['role'] !== 'owner') {
        return ['success' => false, 'error' => 'not_owner'];
    }
    if (!in_array($role, WALLOS_HOUSEHOLD_ROLES, true)) {
        return ['success' => false, 'error' => 'invalid_role'];
    }
    $stmt = $db->prepare('UPDATE household_members SET role = :role WHERE household_id = :householdId AND user_id = :userId');
    $stmt->bindValue(':role', $role, SQLITE3_TEXT);
    $stmt->bindValue(':householdId', (int) $membership['id'], SQLITE3_INTEGER);
    $stmt->bindValue(':userId', (int) $memberUserId, SQLITE3_INTEGER);
    $stmt->execute();

    return $db->changes() > 0 ? ['success' => true] : ['success' => false, 'error' => 'not_member'];
}

/**
 * Takes an account out of a household and makes its subscriptions shared
 * there private again.
 */
/**
 * @return string 'removed', 'not_member' (nothing to remove) or 'write_failed'
 *                (nothing was changed)
 */
function wallos_household_drop_member(SQLite3 $db, $householdId, $memberUserId)
{
    // One transaction: a member is never removed while their subscriptions
    // still name the household (a later rejoin would share them again).
    $status = 'write_failed';
    $committed = wallos_household_transaction($db, function () use ($db, $householdId, $memberUserId, &$status) {
        $stmt = $db->prepare('DELETE FROM household_members WHERE household_id = :householdId AND user_id = :userId');
        $stmt->bindValue(':householdId', (int) $householdId, SQLITE3_INTEGER);
        $stmt->bindValue(':userId', (int) $memberUserId, SQLITE3_INTEGER);
        if (@$stmt->execute() === false) {
            return false;
        }
        if ($db->changes() === 0) {
            $status = 'not_member';
            return false;
        }

        $stmt = $db->prepare('UPDATE subscriptions SET shared_household_id = NULL WHERE user_id = :userId AND shared_household_id = :householdId');
        $stmt->bindValue(':userId', (int) $memberUserId, SQLITE3_INTEGER);
        $stmt->bindValue(':householdId', (int) $householdId, SQLITE3_INTEGER);
        if (@$stmt->execute() === false) {
            return false;
        }

        // Payer labels linked to the member, and the member's own links to
        // the others: an account can only be in one household at a time.
        return wallos_household_clear_links($db, (int) $memberUserId);
    });
    if ($committed) {
        return 'removed';
    }

    return $status === 'not_member' ? 'not_member' : 'write_failed';
}

/**
 * Runs $work in one write transaction; anything but true rolls it back.
 *
 * @return bool
 */
function wallos_household_transaction(SQLite3 $db, callable $work)
{
    if (@$db->exec('BEGIN IMMEDIATE') === false) {
        return false;
    }
    try {
        $ok = $work() === true;
    } catch (Throwable $error) {
        $db->exec('ROLLBACK');
        throw $error;
    }
    if (!$ok || $db->exec('COMMIT') === false) {
        $db->exec('ROLLBACK');
        return false;
    }

    return true;
}

/**
 * The owner removes a member (or withdraws an invite).
 *
 * @return array{success: bool, error?: string}
 */
function wallos_household_remove_member(SQLite3 $db, $ownerId, $memberUserId)
{
    $membership = wallos_household_membership($db, $ownerId);
    if ($membership === null || $membership['role'] !== 'owner') {
        return ['success' => false, 'error' => 'not_owner'];
    }

    $status = wallos_household_drop_member($db, $membership['id'], $memberUserId);

    return $status === 'removed' ? ['success' => true] : ['success' => false, 'error' => $status];
}

/**
 * A member leaves. The owner cannot leave; they delete the household.
 *
 * @return array{success: bool, error?: string}
 */
function wallos_household_leave(SQLite3 $db, $userId)
{
    $membership = wallos_household_membership($db, $userId);
    if ($membership === null) {
        return ['success' => false, 'error' => 'not_member'];
    }
    if ($membership['role'] === 'owner') {
        return ['success' => false, 'error' => 'owner_cannot_leave'];
    }
    $status = wallos_household_drop_member($db, $membership['id'], $userId);

    return $status === 'removed' ? ['success' => true] : ['success' => false, 'error' => $status];
}

/**
 * The owner deletes the household: every subscription shared there is
 * private again and every membership and invite goes.
 *
 * @return array{success: bool, error?: string}
 */
function wallos_household_delete(SQLite3 $db, $ownerId)
{
    $membership = wallos_household_membership($db, $ownerId);
    if ($membership === null || $membership['role'] !== 'owner') {
        return ['success' => false, 'error' => 'not_owner'];
    }
    return wallos_household_delete_by_id($db, (int) $membership['id'])
        ? ['success' => true] : ['success' => false, 'error' => 'write_failed'];
}

/**
 * @return bool false when a write failed (nothing was changed)
 */
function wallos_household_delete_by_id(SQLite3 $db, $householdId)
{
    return wallos_household_transaction($db, function () use ($db, $householdId) {
        return wallos_household_delete_rows($db, $householdId);
    });
}

/**
 * The writes behind wallos_household_delete_by_id(), without a transaction of
 * their own, for a caller that already holds one.
 *
 * @return bool false when a write failed
 */
function wallos_household_delete_rows(SQLite3 $db, $householdId)
{
    foreach ([
        'UPDATE subscriptions SET shared_household_id = NULL WHERE shared_household_id = :householdId',
        // Every payer link between the household's accounts goes with it.
        'UPDATE household SET linked_user_id = NULL WHERE user_id IN (
            SELECT owner_user_id FROM households WHERE id = :householdId
            UNION SELECT user_id FROM household_members WHERE household_id = :householdId)',
        'DELETE FROM household_members WHERE household_id = :householdId',
        'DELETE FROM households WHERE id = :householdId',
    ] as $sql) {
        $stmt = @$db->prepare($sql);
        if ($stmt === false) {
            return false;
        }
        $stmt->bindValue(':householdId', (int) $householdId, SQLITE3_INTEGER);
        if (@$stmt->execute() === false) {
            return false;
        }
    }

    return true;
}

/**
 * Account deletion: a household the account owns is deleted; any other
 * membership or invite goes.
 *
 * Opens no transaction of its own: account deletion runs it inside the one
 * that covers every other table, so a failure here rolls all of it back.
 *
 * @return bool false when a read or write failed
 */
function wallos_household_forget_user(SQLite3 $db, $userId)
{
    $stmt = @$db->prepare('SELECT id FROM households WHERE owner_user_id = :userId');
    if ($stmt === false) {
        return false;
    }
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
    $result = @$stmt->execute();
    if ($result === false) {
        return false;
    }
    $owned = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $owned[] = (int) $row['id'];
    }
    foreach ($owned as $householdId) {
        if (!wallos_household_delete_rows($db, $householdId)) {
            return false;
        }
    }
    if (!wallos_household_clear_links($db, $userId)) {
        return false;
    }
    $stmt = @$db->prepare('DELETE FROM household_members WHERE user_id = :userId');
    if ($stmt === false) {
        return false;
    }
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);

    return @$stmt->execute() !== false;
}

/**
 * The subscription's owner shares it with their household or makes it
 * private. Sharing needs an active household.
 *
 * @return array{success: bool, error?: string}
 */
function wallos_set_subscription_sharing(SQLite3 $db, $userId, $subscriptionId, $shared)
{
    $householdId = null;
    if ($shared) {
        $membership = wallos_household_membership($db, $userId);
        if ($membership === null) {
            return ['success' => false, 'error' => 'not_member'];
        }
        $householdId = (int) $membership['id'];
    }
    $stmt = $db->prepare('UPDATE subscriptions SET shared_household_id = :householdId WHERE id = :id AND user_id = :userId');
    $stmt->bindValue(':householdId', $householdId, $householdId === null ? SQLITE3_NULL : SQLITE3_INTEGER);
    $stmt->bindValue(':id', (int) $subscriptionId, SQLITE3_INTEGER);
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
    $stmt->execute();

    return $db->changes() > 0 ? ['success' => true] : ['success' => false, 'error' => 'not_owner'];
}


/**
 * Every account in a household: its owner and its active members.
 *
 * @return int[]
 */
function wallos_household_account_ids(SQLite3 $db, $householdId)
{
    $ids = [];
    foreach (wallos_household_members($db, $householdId) as $member) {
        if ($member['status'] === 'active') {
            $ids[] = (int) $member['user_id'];
        }
    }

    return $ids;
}

/**
 * The accounts a payer label of $userId can be linked to: the other active
 * accounts of its household, by username.
 *
 * @return array<int, string> user id => username
 */
function wallos_household_link_candidates(SQLite3 $db, $userId)
{
    $membership = wallos_household_membership($db, $userId);
    if ($membership === null) {
        return [];
    }
    $candidates = [];
    foreach (wallos_household_members($db, $membership['id']) as $member) {
        if ($member['status'] === 'active' && (int) $member['user_id'] !== (int) $userId) {
            $candidates[(int) $member['user_id']] = $member['username'];
        }
    }

    return $candidates;
}

/**
 * Links one of the account's payer labels to an account of its household,
 * or unlinks it (null).
 *
 * @return array{success: bool, error?: string}
 */
function wallos_household_set_payer_link(SQLite3 $db, $userId, $payerId, $linkedUserId)
{
    if ($linkedUserId !== null) {
        $linkedUserId = (int) $linkedUserId;
        if (!array_key_exists($linkedUserId, wallos_household_link_candidates($db, $userId))) {
            return ['success' => false, 'error' => 'not_member'];
        }
    }
    $stmt = $db->prepare('UPDATE household SET linked_user_id = :linked WHERE id = :id AND user_id = :userId');
    $stmt->bindValue(':linked', $linkedUserId, $linkedUserId === null ? SQLITE3_NULL : SQLITE3_INTEGER);
    $stmt->bindValue(':id', (int) $payerId, SQLITE3_INTEGER);
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
    if ($stmt->execute() === false) {
        return ['success' => false, 'error' => 'write_failed'];
    }

    return $db->changes() > 0 ? ['success' => true] : ['success' => false, 'error' => 'not_found'];
}

/**
 * Clears the payer links that point to an account, and the ones its own
 * payer labels hold.
 *
 * @return bool false when the write failed
 */
function wallos_household_clear_links(SQLite3 $db, $userId)
{
    $stmt = @$db->prepare('UPDATE household SET linked_user_id = NULL WHERE linked_user_id = :userId OR user_id = :userId');
    if ($stmt === false) {
        return false;
    }
    $stmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);

    return @$stmt->execute() !== false;
}


/**
 * Shared rows with their category, payer and payment method moved to the
 * shared bucket; the owner's ids stay in shared_category_id,
 * shared_payer_id and shared_payment_method_id.
 *
 * @param array[] $rows wallos_shared_subscriptions() rows
 * @return array[]
 */
function wallos_shared_rows_in_bucket(array $rows)
{
    return array_map(function ($row) {
        $row['shared_category_id'] = $row['category_id'] ?? null;
        $row['shared_payer_id'] = $row['payer_user_id'] ?? null;
        $row['shared_payment_method_id'] = $row['payment_method_id'] ?? null;
        $row['category_id'] = WALLOS_SHARED_BUCKET_ID;
        $row['payer_user_id'] = WALLOS_SHARED_BUCKET_ID;
        $row['payment_method_id'] = WALLOS_SHARED_BUCKET_ID;

        return $row;
    }, $rows);
}

/**
 * Whether shared rows belong in a list filtered by these member, category
 * and payment method selections: every dimension that is filtered must
 * include the shared bucket.
 *
 * @param array $selections comma-separated id lists; null or '' = not filtered
 */
function wallos_shared_rows_pass_filters(array $selections)
{
    foreach ($selections as $selection) {
        if ($selection === null || $selection === '') {
            continue;
        }
        $ids = array_map('intval', explode(',', (string) $selection));
        if (!in_array(WALLOS_SHARED_BUCKET_ID, $ids, true)) {
            return false;
        }
    }

    return true;
}
