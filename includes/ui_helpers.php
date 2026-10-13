<?php
/*
  Small rendering helpers shared by the dashboard, the subscriptions list and
  the endpoint that re-renders that list.
*/
require_once __DIR__ . '/logo_theme_variant.php';

// Hue for a subscription's letter tile. Mirrors detailsTileHue() in
// scripts/subscription-details.js so a subscription keeps its colour.
function tile_hue($name)
{
    $hash = 0;
    $chars = preg_split('//u', (string) $name, -1, PREG_SPLIT_NO_EMPTY);
    foreach ($chars as $char) {
        $hash = ($hash * 31 + mb_ord($char, 'UTF-8')) % 4294967296;
    }
    return $hash % 360;
}

/*
  The square tile at the start of a row: the subscription's logo when it has
  one, otherwise its first letter on a colour derived from the name.
  $logoSrc / $variantSrc are full paths (or empty).
*/
function render_tile($name, $logoSrc = '', $variantSrc = null, $textColor = null, $extraClass = '')
{
    $class = trim('tile ' . $extraClass);
    if (!empty($logoSrc)) {
        return '<span class="' . $class . ' tile-logo" aria-hidden="true">'
            . renderThemedLogoImg($logoSrc, $variantSrc, $textColor, '', 'alt=""')
            . '</span>';
    }
    $initial = mb_strtoupper(mb_substr(trim(htmlspecialchars_decode((string) $name)), 0, 1, 'UTF-8'), 'UTF-8');
    return '<span class="' . $class . '" style="--tile-hue: ' . tile_hue(htmlspecialchars_decode((string) $name)) . '" aria-hidden="true">'
        . htmlspecialchars($initial) . '</span>';
}

/*
  One row of a grouped list: tile, name, subtitle and a trailing value.
  $row keys: id, name, logo, logo_variant, logo_text_color, subtitle,
  subtitle_tone ('' | 'danger'), trailing, trailing_sub, dim (bool),
  progress (null | 0..100). Name and text values are escaped here.
*/
function render_list_row($row)
{
    $id = (int) $row['id'];
    $classes = 'list-row' . (!empty($row['dim']) ? ' is-dim' : '');
    ob_start();
    ?>
    <button type="button" class="<?= $classes ?>" data-id="<?= $id ?>"
        data-name="<?= htmlspecialchars(htmlspecialchars_decode($row['name']), ENT_QUOTES) ?>"
        onClick="showSubscriptionDetails(event, <?= $id ?>)">
        <?= render_tile($row['name'], $row['logo'] ?? '', $row['logo_variant'] ?? null, $row['logo_text_color'] ?? null) ?>
        <span class="list-row-text">
            <span class="list-row-title"><?= htmlspecialchars(htmlspecialchars_decode($row['name'])) ?></span>
            <span class="list-row-subtitle<?= !empty($row['subtitle_tone']) ? ' is-' . $row['subtitle_tone'] : '' ?>"><?= htmlspecialchars($row['subtitle'] ?? '') ?></span>
            <?php if (isset($row['progress']) && $row['progress'] !== null) { ?>
                <span class="list-row-progress" aria-hidden="true"><span style="width: <?= max(0, min(100, (int) $row['progress'])) ?>%"></span></span>
            <?php } ?>
        </span>
        <span class="list-row-trailing">
            <span class="list-row-value"><?= htmlspecialchars($row['trailing'] ?? '') ?></span>
            <?php if (!empty($row['trailing_sub'])) { ?>
                <span class="list-row-value-sub"><?= htmlspecialchars($row['trailing_sub']) ?></span>
            <?php } ?>
        </span>
    </button>
    <?php
    return ob_get_clean();
}

// Short relative due text: "Today", "Tomorrow", "In 3 days", or a date.
function due_text($date, $lang, $i18n)
{
    $today = new DateTime('today');
    $due = new DateTime($date);
    $days = (int) $today->diff($due)->format('%r%a');
    if ($days === 0) {
        return translate('today', $i18n);
    }
    if ($days === 1) {
        return translate('tomorrow', $i18n);
    }
    if ($days > 1 && $days < 7) {
        return sprintf(translate('in_days', $i18n), $days);
    }
    return formatDate($date, $lang);
}

function days_overdue($date)
{
    $today = new DateTime('today');
    $due = new DateTime($date);
    return max(0, (int) $due->diff($today)->format('%r%a'));
}
