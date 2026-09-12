<?php

return [
    // Instance display name. Editable from Settings › General (writes SITE_NAME to .env).
    'site_name' => env('SITE_NAME', 'Thallo'),

    // Glueful storage disk that backs media blob references (see docs/internal/V1_DESIGN.md §8).
    // MUST match the disk uploads land on, or asset-field validation rejects every
    // library image ("must reference an active blob on the configured media disk").
    // The framework writes blobs to `uploads.disk` (env UPLOADS_DISK, default 'uploads'),
    // so this default mirrors it; set MEDIA_DISK only to point validation at a different disk.
    'media_disk' => env('MEDIA_DISK', env('UPLOADS_DISK', 'uploads')),

    // First-run web setup (POST /admin/setup) guard. The endpoint is unauthenticated by design
    // (no admin exists yet), so on a public deploy it must not be "first caller owns the instance":
    //   - When a token is set, the request must carry it via the X-Setup-Token header (hash_equals).
    //   - It is REQUIRED in production: with no token set, POST /admin/setup is refused (403) so the
    //     operator provisions via `SETUP_TOKEN` (or the CLI admin command).
    //   - In non-production, an unset token keeps zero-config local setup working.
    'setup' => [
        'token' => env('SETUP_TOKEN'),
    ],

    // Seeded role names (see docs/internal/V1_DESIGN.md §7).
    'roles' => [
        // The first admin uses Aegis's standard `administrator` role; `editor` is Thallo-owned.
        'admin' => 'administrator',
        'editor' => 'editor',
    ],

    // Collections public read API defaults (see packages/thallo-collections).
    'collections' => [
        // Default page size when the request omits perPage.
        'default_per_page' => (int) env('COLLECTIONS_DEFAULT_PER_PAGE', 20),
        // Hard cap on page size to keep latency predictable.
        'max_per_page' => (int) env('COLLECTIONS_MAX_PER_PAGE', 100),
        // Hard cap on rows per bulk-create request.
        'max_bulk' => (int) env('COLLECTIONS_MAX_BULK', 100),
    ],

    // Public delivery API defaults (see docs/internal/V1_DESIGN.md §6). Delivery is private by
    // default: clients need read:content or read:content:{type}, unless a content type sets
    // public_delivery=true.
    'delivery' => [
        // Default page size when the request omits perPage.
        'default_per_page' => (int) env('DELIVERY_DEFAULT_PER_PAGE', 20),
        // Hard cap on page size to keep latency predictable.
        'max_per_page' => (int) env('DELIVERY_MAX_PER_PAGE', 100),
        // Cache-Control max-age (seconds) emitted on delivery responses when the
        // content type has no cache_ttl override.
        'cache_ttl' => (int) env('DELIVERY_CACHE_TTL', 60),
    ],

    // Headless SEO/routing helpers. Paths are rendered as public-site paths, never API
    // URLs. Leave public_url_base empty to return relative paths for the frontend to
    // make absolute.
    'seo' => [
        'route_template' => env('SEO_ROUTE_TEMPLATE', '/{locale}/{type}/{slug}'),
        'public_url_base' => env('PUBLIC_URL_BASE'),
        'redirect_ttl' => (int) env('SEO_REDIRECT_TTL', 60),
    ],

    // Preview tokens (see docs/internal/V1_DESIGN.md). Drafts are only reachable through a
    // signed, short-lived preview token; this is its lifetime in seconds.
    'preview' => [
        'ttl_seconds' => (int) env('PREVIEW_TTL', 600),
    ],

    // Downstream publishing-pipeline effects (see docs/internal/V1_DESIGN.md §5). Each listener is
    // gated here so a deployment can opt out without unwiring the event bus.
    'pipeline' => [
        // Forward content events to the core WebhookDispatcher. Deliveries only occur for
        // events that have an active subscription, so this is safe to leave on.
        'webhooks_enabled' => (bool) env('PIPELINE_WEBHOOKS_ENABLED', true),
    ],

    // Admin SPA runtime config (served UNAUTHENTICATED at GET /admin/config so the
    // compiled bundle is not env-baked — one build works across installs). See
    // docs/internal/superpowers/specs/2026-06-17-admin-spa-phase-1-design.md §"Runtime config".
    'admin' => [
        // The admin API base PATH the SPA calls. Thallo's admin routes are hardcoded /v1/admin.
        // The admin is served same-origin (the PHP app serves both /admin and the API), so this is
        // a relative path.
        'api_base' => env('ADMIN_API_BASE', '/v1/admin'),
        // The frontend preview URL template; the SPA appends/embeds the minted token.
        'site_preview_url' => env('SITE_PREVIEW_URL', ''),
        // Phase 1 is en-only in the UI; locale stays in the data model.
        'default_locale' => env('ADMIN_DEFAULT_LOCALE', (string) env('I18N_DEFAULT_LOCALE', 'en')),
        // Whether the default first-party admin SPA is mounted at /admin. The bundled admin is a
        // REPLACEABLE client of the /v1/admin API — set this false to bring your own (point
        // bundle_path at your build, or disable and register a different mount in a provider).
        'enabled' => (bool) env('ADMIN_ENABLED', true),
        // Filesystem dir of the compiled SPA bundle the framework serveFrontend() seam mounts
        // at /admin. Defaults to core/resources/admin (baked into the release tag; provision also
        // publishes a copy into public/admin so the web server serves the assets from disk; see
        // release.yml; gitignored in dev). Override for tests/relocation/a custom admin.
        'bundle_path' => env('ADMIN_BUNDLE_PATH', dirname(__DIR__) . '/resources/admin'),
    ],

    // Capability switchboard for first-party packs. Each installed pack registers a
    // Capability (id like 'thallo.forms') into the CapabilityRegistry; it is ENABLED by
    // default. List a full capability id here as `false` to DISABLE it without
    // uninstalling the pack (routes/jobs/subscribers/admin contributions are gated by
    // enabled state; migrations are not — they run when installed). Keys are full
    // capability ids (with dots); this whole map is read at once, never via dotted access.
    'capabilities' => [
        // Parity migration (modules-not-extensions spec §5.2): Search was previously OFF by
        // provider absence (never in the extensions activation list). As an always-loaded
        // module the registry's absent-key default would silently enable it — this explicit
        // default keeps it off until deliberately switched on.
        'thallo.search' => false,
        // 'thallo.forms' => false,
    ],

    // Scheduled publish/unpublish. The framework scheduler's per-job `enabled` key is not
    // the gate; ScheduleRunner reads this switch before firing any due rows.
    'scheduler' => [
        'enabled' => (bool) env('CONTENT_SCHEDULER_ENABLED', true),
    ],

    // Version retention / pruning. Raw env pass-through: do not cast here.
    // RetentionPolicy::fromValues() validates positive integers and treats null/'' as off.
    'versions' => [
        'retention' => [
            'keep' => env('VERSION_KEEP'),
            'max_age_days' => env('VERSION_MAX_AGE_DAYS'),
        ],
    ],
];
