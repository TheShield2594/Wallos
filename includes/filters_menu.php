<?php require_once __DIR__ . '/default_names.php'; ?>
<div class="filtermenu-content">
  <?php
  if (count($members) > 1 || !empty($sharedFilterAvailable)) {
    ?>
    <div class="filtermenu-submenu">
      <div class="filter-title" onClick="toggleSubMenu('member')"><?= translate("member", $i18n) ?></div>
      <div class="filtermenu-submenu-content" id="filter-member">
        <?php
        foreach ($members as $member) {
          if ($member['count'] == 0) {
            continue;
          }
          $selectedClass = '';
          if (isset($_GET['member'])) {
            $memberIds = explode(',', $_GET['member']);
            if (in_array($member['id'], $memberIds)) {
              $selectedClass = 'selected';
            }
          }
          ?>
          <div class="filter-item <?= $selectedClass ?>" data-memberid="<?= $member['id'] ?>"><?= $member['name'] ?>
          </div>
          <?php
        }
        ?>
        <?php if (!empty($sharedFilterAvailable)) {
          $isSelected = isset($_GET['member']) && in_array(WALLOS_SHARED_BUCKET_ID, array_map('intval', explode(',', $_GET['member'])), true);
          ?>
          <div class="filter-item <?= $isSelected ? 'selected' : '' ?>" data-memberid="<?= WALLOS_SHARED_BUCKET_ID ?>"><?= translate('shared_bucket', $i18n) ?></div>
        <?php } ?>
      </div>
    </div>
    <?php
  }
  ?>
  <?php
  if (count($categories) > 1 || !empty($sharedFilterAvailable)) {
    ?>
    <div class="filtermenu-submenu">
      <div class="filter-title" onClick="toggleSubMenu('category')"><?= translate("category", $i18n) ?></div>
      <div class="filtermenu-submenu-content" id="filter-category">
        <?php
        // A category still carrying its untouched default name is
        // recognised against the account's own creation-language default,
        // not a literal English string - #1216 gives every language its
        // own default, so an account created in German never had an
        // English name to match here.
        $noCategoryDefaultName = default_no_category_name($userData['language'] ?? 'en');
        foreach ($categories as $category) {
          if ($category['count'] == 0) {
            continue;
          }
          if ($category['name'] === $noCategoryDefaultName) {
            $category['name'] = translate("no_category", $i18n);
          }
          $selectedClass = '';
          if (isset($_GET['category'])) {
            $categoryIds = explode(',', $_GET['category']);
            if (in_array($category['id'], $categoryIds)) {
              $selectedClass = 'selected';
            }
          }
          ?>
          <div class="filter-item <?= $selectedClass ?>" data-categoryid="<?= $category['id'] ?>">
            <?= $category['name'] ?>
          </div>
          <?php
        }
        ?>
        <?php if (!empty($sharedFilterAvailable)) {
          $isSelected = isset($_GET['category']) && in_array(WALLOS_SHARED_BUCKET_ID, array_map('intval', explode(',', $_GET['category'])), true);
          ?>
          <div class="filter-item <?= $isSelected ? 'selected' : '' ?>" data-categoryid="<?= WALLOS_SHARED_BUCKET_ID ?>"><?= translate('shared_bucket', $i18n) ?></div>
        <?php } ?>
      </div>
    </div>
    <?php
  }
  ?>
  <?php
  if (count($payment_methods) > 1 || !empty($sharedFilterAvailable)) {
    ?>
    <div class="filtermenu-submenu">
      <div class="filter-title" onClick="toggleSubMenu('payment')"><?= translate("payment_method", $i18n) ?></div>
      <div class="filtermenu-submenu-content" id="filter-payment">
        <?php
        foreach ($payment_methods as $payment) {
          if ($payment['count'] == 0) {
            continue;
          }
          $selectedClass = '';
          if (isset($_GET['payment'])) {
            $paymentIds = explode(',', $_GET['payment']);
            if (in_array($payment['id'], $paymentIds)) {
              $selectedClass = 'selected';
            }
          }
          ?>
          <div class="filter-item <?= $selectedClass ?>" data-paymentid="<?= $payment['id'] ?>">
            <?= $payment['name'] ?>
          </div>
          <?php
        }
        ?>
        <?php if (!empty($sharedFilterAvailable)) {
          $isSelected = isset($_GET['payment']) && in_array(WALLOS_SHARED_BUCKET_ID, array_map('intval', explode(',', $_GET['payment'])), true);
          ?>
          <div class="filter-item <?= $isSelected ? 'selected' : '' ?>" data-paymentid="<?= WALLOS_SHARED_BUCKET_ID ?>"><?= translate('shared_bucket', $i18n) ?></div>
        <?php } ?>
      </div>
    </div>
    <?php
  }
  ?>
  <?php
  if (!isset($settings['hideDisabledSubscriptions']) || $settings['hideDisabledSubscriptions'] !== 'true') {
    ?>
    <div class="filtermenu-submenu">
      <div class="filter-title" onClick="toggleSubMenu('state')"><?= translate("state", $i18n) ?></div>
      <div class="filtermenu-submenu-content" id="filter-state">
        <div class="filter-item capitalize" data-state="0"><?= translate("enabled", $i18n) ?></div>
        <div class="filter-item capitalize" data-state="1"><?= translate("disabled", $i18n) ?></div>
      </div>
    </div>
    <?php
  }
  ?>

  <div class="filtermenu-submenu">
    <div class="filter-title" onClick="toggleSubMenu('renewal_type')"><?= translate("renewal_type", $i18n) ?></div>
    <div class="filtermenu-submenu-content" id="filter-renewal_type">
      <div class="filter-item capitalize" data-renewaltype="1"><?= translate("auto_renewal", $i18n) ?></div>
      <div class="filter-item capitalize" data-renewaltype="0"><?= translate("manual_renewal", $i18n) ?></div>
      <div class="filter-item capitalize" data-renewaltype="onetime"><?= translate("One-time", $i18n) ?></div>
    </div>
  </div>

  <div class="filtermenu-submenu">
    <div class="filter-title" onClick="toggleSubMenu('notification')"><?= translate("notifications", $i18n) ?></div>
    <div class="filtermenu-submenu-content" id="filter-notification">
      <?php
      // Pre-selected when the page was opened with ?notifications=...
      $selectedNotificationTypes = wallos_notification_filter_types($_GET['notifications'] ?? '');
      foreach (['reminder' => 'reminder', 'cancellation' => 'cancellation', 'none' => 'none'] as $notificationType => $notificationLabel) {
        ?>
        <div class="filter-item capitalize<?= in_array($notificationType, $selectedNotificationTypes, true) ? ' selected' : '' ?>"
          data-notificationtype="<?= $notificationType ?>"><?= translate($notificationLabel, $i18n) ?></div>
      <?php } ?>
    </div>
  </div>

  <?php if (!empty($hasBundleChildren)) { ?>
    <?php // Bundles (issue #24): a view switch kept in the browser, not a filter. ?>
    <div class="filtermenu-submenu">
      <div class="filter-title" id="toggle-bundle-children" role="switch" aria-checked="false" tabindex="0"
        onClick="toggleBundleChildren()" onKeyDown="if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); toggleBundleChildren(); }">
        <i class="fa-solid fa-layer-group" aria-hidden="true"></i> <?= translate('bundle_hide_included', $i18n) ?>
      </div>
    </div>
  <?php } ?>

  <div class="filtermenu-submenu hide" id="clear-filters">
    <div class="filter-title filter-clear" onClick="clearFilters()">
      <i class="fa-solid fa-times-circle"></i> <?= translate("clear", $i18n) ?>
    </div>
  </div>
</div>