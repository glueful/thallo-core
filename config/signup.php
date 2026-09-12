<?php

declare(strict_types=1);

return [
    'workspaces' => [
        'enabled' => false,
    ],
    'intent_ttl_seconds' => (int) env('SIGNUP_INTENT_TTL_SECONDS', 86400),
    'consumed_retention_days' => (int) env('SIGNUP_CONSUMED_RETENTION_DAYS', 7),
    'otp' => [
        'attempts' => (int) env('SIGNUP_OTP_ATTEMPTS', 5),
        'ttl_seconds' => (int) env('SIGNUP_OTP_TTL_SECONDS', 900),
    ],
    'continuation' => [
        'grace_seconds' => (int) env('SIGNUP_CONTINUATION_GRACE_SECONDS', 300),
    ],
    'limits' => [
        'window_seconds' => (int) env('SIGNUP_RATE_WINDOW_SECONDS', 3600),
        'per_ip' => (int) env('SIGNUP_RATE_PER_IP', 10),
        'per_email' => (int) env('SIGNUP_RATE_PER_EMAIL', 5),
        'resend_per_intent' => (int) env('SIGNUP_RESEND_PER_INTENT', 3),
        'member_daily' => (int) env('SIGNUP_MEMBER_DAILY_CAP', 500),
        'workspace_daily' => (int) env('SIGNUP_WORKSPACE_DAILY_CAP', 100),
    ],
    'challenge' => [
        'provider' => env('SIGNUP_CHALLENGE_PROVIDER'),
    ],
];
