<?php
// Tally logo: the app icon followed by the name in the system font. The
// gradient id is numbered so the logo can appear more than once on a page.
$tallyLogoId = 'tally-bg-' . ($GLOBALS['tallyLogoCount'] = ($GLOBALS['tallyLogoCount'] ?? 0) + 1);
?>
<span class="brand">
    <svg class="brand-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" aria-hidden="true" focusable="false">
        <defs>
            <linearGradient id="<?= $tallyLogoId ?>" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0" stop-color="#5EA8FA" />
                <stop offset="0.45" stop-color="#2F72EE" />
                <stop offset="1" stop-color="#1846D6" />
            </linearGradient>
        </defs>
        <rect width="100" height="100" rx="25" fill="url(#<?= $tallyLogoId ?>)" />
        <path d="M26.9 27v46M42.3 27v46M57.7 27v46M73.1 27v46M16.5 61.5 83.5 36" stroke="#FFFFFF"
            stroke-width="8.8" stroke-linecap="round" fill="none" />
    </svg>
    <span class="brand-name">Tally</span>
</span>
