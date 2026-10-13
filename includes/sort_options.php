<?php
$sortChoices = [
    'next_payment' => translate('next_payment', $i18n),
    'name' => translate('name', $i18n),
    'alphanumeric' => translate('alphanumeric', $i18n),
    'price' => translate('price', $i18n),
    'id' => translate('last_added', $i18n),
    'category_id' => translate('category', $i18n),
    'payer_user_id' => translate('member', $i18n),
    'payment_method_id' => translate('payment_method', $i18n),
    'renewal_type' => translate('renewal_type', $i18n),
];
if (!isset($settings['hideDisabledSubscriptions']) || $settings['hideDisabledSubscriptions'] !== 'true') {
    $sortChoices['inactive'] = translate('state', $i18n);
}
?>
<select id="sort-select" class="toolbar-select" aria-label="<?= translate('sort', $i18n) ?>"
    onChange="setSortOption(this.value)">
    <?php foreach ($sortChoices as $value => $label) { ?>
        <option value="<?= $value ?>" <?= $sortOrder == $value ? 'selected' : '' ?>><?= $label ?></option>
    <?php } ?>
</select>
