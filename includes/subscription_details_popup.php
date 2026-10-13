<?php
/*
  Shared subscription details popup (backdrop + dialog + lookups).
  Include once near the end of the page, before the page's own scripts.
  Requires: $i18n, $version, $subscriptions, and the getdbkeys.php arrays.
  Runs no queries of its own — on some pages $db is already closed here.
*/
require_once __DIR__ . '/getdbkeys.php';
?>

<div class="details-backdrop" id="details-backdrop" onClick="closeSubscriptionDetails()"></div>
<aside class="detail-panel" id="subscription-details" aria-labelledby="details-name" tabindex="-1">
  <div class="detail-bar">
    <button type="button" class="detail-back" onClick="closeSubscriptionDetails()">
      <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
      <span class="compact-only"><?= translate('back', $i18n) ?></span>
      <span class="compact-hide"><?= translate('close', $i18n) ?></span>
    </button>
    <span class="detail-hint"><?= translate('esc_to_close', $i18n) ?></span>
  </div>

  <div class="detail-empty" id="details-empty">
    <i class="fa-solid fa-list-ul" aria-hidden="true"></i>
    <p><?= translate('select_a_subscription', $i18n) ?></p>
  </div>

  <div class="detail-body">
    <header class="detail-hero">
      <span class="tile tile-lg" id="details-logo"></span>
      <div class="detail-hero-text">
        <h2 id="details-name"></h2>
        <div class="detail-price-line">
          <span class="detail-price"><span id="details-price"></span> <span class="detail-per" id="details-billing-cycle"></span></span>
          <span class="detail-status" id="details-status"></span>
        </div>
      </div>
    </header>

    <div class="detail-actions">
      <button type="button" class="btn btn-primary hide" id="details-renew-button"><?= translate('mark_as_paid', $i18n) ?></button>
      <button type="button" class="btn" id="details-edit-button"><?= translate('edit', $i18n) ?></button>
      <button type="button" class="btn" id="details-clone-button"><?= translate('clone', $i18n) ?></button>
      <button type="button" class="btn" id="details-export-button"><?= translate('add_to_calendar', $i18n) ?></button>
      <a class="btn hide" id="details-url-button" href="#" target="_blank" rel="noreferrer"><?= translate('website', $i18n) ?></a>
      <button type="button" class="btn btn-destructive" id="details-delete-button"><?= translate('delete', $i18n) ?></button>
    </div>

    <div class="detail-grid">
      <section class="card">
        <h3 class="card-title"><?= translate('payment', $i18n) ?></h3>
        <dl class="kv-list">
          <div class="kv-row">
            <dt id="details-next-payment-label"><?= translate('next_payment', $i18n) ?></dt>
            <dd id="details-next-payment"></dd>
          </div>
          <div class="kv-row">
            <dt><?= translate('billing_cycle', $i18n) ?></dt>
            <dd id="details-cycle"></dd>
          </div>
          <div class="kv-row" id="details-renewal-item">
            <dt><?= translate('renewal', $i18n) ?></dt>
            <dd id="details-renewal"></dd>
          </div>
          <div class="kv-row">
            <dt><?= translate('payment_method', $i18n) ?></dt>
            <dd id="details-payment-method">
              <img id="details-payment-icon" src="" alt="">
              <span id="details-payment-name"></span>
            </dd>
          </div>
          <div class="kv-row">
            <dt><?= translate('paid_by', $i18n) ?></dt>
            <dd id="details-payer"></dd>
          </div>
          <div class="kv-row">
            <dt><?= translate('category', $i18n) ?></dt>
            <dd id="details-category"></dd>
          </div>
          <div class="kv-row">
            <dt><?= translate('start_date', $i18n) ?></dt>
            <dd id="details-start-date"></dd>
          </div>
          <div class="kv-row hide" id="details-cancellation-item">
            <dt><?= translate('cancellation_notification', $i18n) ?></dt>
            <dd id="details-cancellation"></dd>
          </div>
          <div class="kv-row hide" id="details-replacement-item">
            <dt><?= translate('replaced_with', $i18n) ?></dt>
            <dd id="details-replacement"></dd>
          </div>
        </dl>
      </section>

      <div class="detail-column">
        <section class="card" id="details-notifications-card">
          <h3 class="card-title"><?= translate('notifications', $i18n) ?></h3>
          <div class="form-row">
            <span id="details-remind-label"><?= translate('payment_reminder', $i18n) ?></span>
            <button type="button" class="switch" role="switch" id="details-notify-switch" aria-checked="false"
              aria-labelledby="details-remind-label"></button>
          </div>
          <div class="form-row" id="details-lead-row">
            <label for="details-lead-select"><?= translate('remind_me', $i18n) ?></label>
            <select id="details-lead-select" class="select-compact">
              <option value="-1"><?= translate('default_value_from_settings', $i18n) ?></option>
              <option value="0"><?= translate('on_due_date', $i18n) ?></option>
              <option value="1">1 <?= translate('day_before', $i18n) ?></option>
              <?php foreach ([2, 3, 5, 7, 10, 14, 30] as $leadDays) { ?>
                <option value="<?= $leadDays ?>"><?= $leadDays ?> <?= translate('days_before', $i18n) ?></option>
              <?php } ?>
            </select>
          </div>
          <p class="card-footnote" id="details-notify-note"></p>
        </section>

        <section class="card hide" id="details-notes-item">
          <h3 class="card-title"><?= translate('notes', $i18n) ?></h3>
          <div class="detail-notes" id="details-notes"></div>
        </section>
      </div>
    </div>
  </div>
</aside>

<?php
$detailsLookups = [
  'categories' => new stdClass(),
  'members' => new stdClass(),
  'paymentMethods' => new stdClass(),
  'currencies' => new stdClass(),
  'subscriptionNames' => new stdClass(),
  'cycles' => [
    1 => ['one' => translate('Daily', $i18n), 'many' => translate('days', $i18n)],
    2 => ['one' => translate('Weekly', $i18n), 'many' => translate('weeks', $i18n)],
    3 => ['one' => translate('Monthly', $i18n), 'many' => translate('months', $i18n)],
    4 => ['one' => translate('Yearly', $i18n), 'many' => translate('years', $i18n)],
    5 => ['one' => translate('One-time', $i18n), 'many' => translate('One-time', $i18n)],
  ],
  'i18n' => [
    'automatic' => translate('automatic', $i18n),
    'manual_renewal' => translate('manual_renewal', $i18n),
    'inactive' => translate('disabled', $i18n),
    'one_time' => translate('One-time', $i18n),
    'enabled' => translate('enabled', $i18n),
    'disabled' => translate('disabled', $i18n),
    'on_due_date' => translate('on_due_date', $i18n),
    'day_before' => translate('day_before', $i18n),
    'days_before' => translate('days_before', $i18n),
    'none' => translate('none', $i18n),
    'next_payment' => translate('next_payment', $i18n),
    'payment_date' => translate('payment_date', $i18n),
    'per_month' => translate('per_month', $i18n),
    'per_year' => translate('per_year', $i18n),
    'every' => translate('every', $i18n),
    'lifetime_purchase' => translate('lifetime_purchase', $i18n),
    'paid_on' => translate('paid_on', $i18n),
    'next_payment_today' => translate('next_payment_today', $i18n),
    'next_payment_tomorrow' => translate('next_payment_tomorrow', $i18n),
    'next_payment_in_days' => translate('next_payment_in_days', $i18n),
    'overdue_since' => translate('overdue_since', $i18n),
    'reminder_note_on' => translate('reminder_note_on', $i18n),
    'reminder_note_off' => translate('reminder_note_off', $i18n),
  ],
];
foreach ($categories as $categoryId => $category) {
  $detailsLookups['categories']->{$categoryId} = $category['name'];
}
foreach ($members as $memberId => $member) {
  $detailsLookups['members']->{$memberId} = $member['name'];
}
foreach ($payment_methods as $paymentMethodId => $paymentMethod) {
  $paymentIconFolder = (strpos($paymentMethod['icon'], 'images/uploads/icons/') !== false) ? "" : "images/uploads/logos/";
  $detailsLookups['paymentMethods']->{$paymentMethodId} = [
    'name' => $paymentMethod['name'],
    'icon' => $paymentIconFolder . $paymentMethod['icon'],
  ];
}
foreach ($currencies as $currencyId => $currency) {
  $detailsLookups['currencies']->{$currencyId} = [
    'code' => $currency['code'],
    'symbol' => $currency['symbol'],
  ];
}
foreach ($subscriptions as $detailsSubscription) {
  $detailsLookups['subscriptionNames']->{$detailsSubscription['id']} = $detailsSubscription['name'];
}
?>
<script>
  window.subscriptionLookups = <?= json_encode($detailsLookups, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
</script>
<script src="scripts/subscription-details.js?<?= $version ?>"></script>
