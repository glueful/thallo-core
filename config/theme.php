<?php

declare(strict_types=1);

return [
    // Color mode (color-mode spec §3.4). false ⇒ no resolver, no marker, no toggle UI;
    // the site renders light-only regardless of any stored visitor preference.
    'color_mode' => [
        'enabled' => (bool) env('THALLO_COLOR_MODE_ENABLED', true),
    ],
];
