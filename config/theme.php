<?php

declare(strict_types=1);

return [
    // Color mode (color-mode spec §3.4). false ⇒ no resolver, no marker, no toggle UI;
    // the site renders light-only regardless of any stored visitor preference.
    'color_mode' => [
        'enabled' => (bool) env('THALLO_COLOR_MODE_ENABLED', true),
    ],
    // Brand colours (custom palette spec §1): how many a workspace may have at once — a budget the
    // operator sets, not a site setting. Clamped to 0–12; 0 turns brand colours off.
    'brand_colors' => [
        'max' => env('THALLO_BRAND_COLORS_MAX', 3),
    ],
];
