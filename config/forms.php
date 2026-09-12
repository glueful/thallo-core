<?php

declare(strict_types=1);

return [
    // Descriptor lifetime; the sealer uses max(this, render_cache_ttl + buffer).
    'descriptor_max_age' => (int) env('FORMS_DESCRIPTOR_MAX_AGE', 1209600), // 14 days
    'descriptor_buffer'  => (int) env('FORMS_DESCRIPTOR_BUFFER', 3600),     // 1 hour
    // Time-trap floor (seconds). A submit faster than this is treated as a bot.
    'min_seconds'        => (int) env('FORMS_MIN_SECONDS', 2),
    // Per form_key + IP rate limit.
    'rate_limit'         => ['max' => (int) env('FORMS_RATE_MAX', 5), 'window' => (int) env('FORMS_RATE_WINDOW', 60)],
    // Fallback recipient when a block leaves recipient empty. Empty => forms with
    // no block recipient are un-routable (sealer refuses).
    'default_recipient'  => (string) env('FORMS_DEFAULT_RECIPIENT', ''),
];
