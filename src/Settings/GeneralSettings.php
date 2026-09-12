<?php

declare(strict_types=1);

namespace Thallo\Core\Settings;

use Glueful\Bootstrap\ApplicationContext;

/**
 * Effective instance "General" settings: a `settings` row overrides the deploy-time
 * config/.env default. Precedence: DB row → `config('thallo.*')` (which reads .env) → hard default.
 *
 * This is the single read point for these settings so a save (to `settings`) takes effect on
 * the next request across every instance, with no `.env` rewrite or restart. Consumers call e.g.
 * `app($context, GeneralSettings::class)->maxPerPage()` instead of `config('thallo.delivery.max_per_page')`.
 */
final class GeneralSettings
{
    /** Setting key => [config path used as the deploy-time default, value type, hard fallback]. */
    private const DEFS = [
        'site_name'         => ['thallo.site_name', 'string', 'Thallo'],
        'site_preview_url'  => ['thallo.admin.site_preview_url', 'string', ''],
        'default_locale'    => ['thallo.admin.default_locale', 'string', 'en'],
        'default_per_page'  => ['thallo.delivery.default_per_page', 'int', 20],
        'max_per_page'      => ['thallo.delivery.max_per_page', 'int', 100],
        'cache_ttl'         => ['thallo.delivery.cache_ttl', 'int', 60],
        'scheduler_enabled' => ['thallo.scheduler.enabled', 'bool', true],
        'webhooks_enabled'  => ['thallo.pipeline.webhooks_enabled', 'bool', true],
        // The `thallo.search` capability switch. Its deploy default lives in the
        // `thallo.capabilities` MAP, whose keys contain dots — dotted config access
        // can't reach it, so value() resolves this key's default specially (absent
        // key = enabled, matching DefaultCapabilityRegistry semantics). The stored
        // row feeds back into the registry via makeCapabilityRegistry().
        'search_enabled'    => ['', 'bool', true],
        'homepage_entry'    => ['render.homepage_entry', 'string', ''],
        'site_logo'         => ['thallo.site_logo', 'string', ''],
        // Dark-scheme logo variant (site-identity spec): an OVERRIDE — unset
        // means the main logo renders in dark mode too.
        'site_logo_dark'    => ['thallo.site_logo_dark', 'string', ''],
        // Favicon blob uuid; rendered only when anonymously servable.
        'site_favicon'      => ['thallo.site_favicon', 'string', ''],
        // Live theme (theme-setting spec §1): DB override → RENDER_THEME env →
        // 'default'. Write-validated; explicit '' clears to the env fallback.
        'theme'             => ['render.theme', 'string', 'default'],
        // Theme color config (theme-color-config spec §2): accent + neutral
        // Tailwind families; DB row → config → blue/slate. Enum-validated on save.
        'theme_accent'      => ['thallo.theme.accent', 'string', 'blue'],
        'theme_neutral'     => ['thallo.theme.neutral', 'string', 'slate'],
        // Admin SPA base URL — powers the preview bar's Edit/Design deep links.
        // Auto-populated at web setup (the SPA sends its own origin).
        'admin_url'         => ['render.admin_url', 'string', ''],
        // Which content types expose /{type} listings + /{type}/{field}/{term}
        // archives. DB row wins (CSV; '' = explicitly none); config/.env is the
        // pre-first-save deploy default.
        'listing_types'     => ['render.listing_types', 'list', []],
    ];

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly SettingsStore $store,
        private readonly \Thallo\Core\Capabilities\CapabilityStateStore $capabilityState,
    ) {
    }

    public function siteName(): string
    {
        return (string) $this->value('site_name');
    }

    /** Admin SPA base URL; '' hides the preview bar's Edit/Design links. */
    public function adminUrl(): string
    {
        return (string) $this->value('admin_url');
    }

    /** The effective listing-types allowlist (render grammar gate). @return list<string> */
    public function listingTypes(): array
    {
        return array_values(array_filter(array_map(strval(...), (array) $this->value('listing_types'))));
    }

    /** Asset uuid of the site logo; '' when unset (blocks fall back to the site name). */
    public function siteLogo(): string
    {
        return (string) $this->value('site_logo');
    }

    /** Dark-scheme logo variant uuid; '' = no override (the main logo renders). */
    public function siteLogoDark(): string
    {
        return (string) $this->value('site_logo_dark');
    }

    /** Favicon blob uuid; '' when unset. */
    public function siteFavicon(): string
    {
        return (string) $this->value('site_favicon');
    }

    /** The EFFECTIVE live theme (row → env → 'default'). */
    public function theme(): string
    {
        return (string) $this->value('theme');
    }

    public function themeAccent(): string
    {
        return (string) $this->value('theme_accent');
    }

    public function themeNeutral(): string
    {
        return (string) $this->value('theme_neutral');
    }

    /**
     * The RAW stored theme override — null when no DB row (env applies). The
     * homepageEntryOverride() mirror: providers must never surface the env
     * fallback as if it were a stored override (theme-setting spec §5).
     */
    public function themeOverride(): ?string
    {
        return $this->store->get('theme');
    }

    public function sitePreviewUrl(): string
    {
        return (string) $this->value('site_preview_url');
    }

    public function defaultLocale(): string
    {
        return (string) $this->value('default_locale');
    }

    /** The RAW stored homepage override — null when no DB row (env applies). */
    public function homepageEntryOverride(): ?string
    {
        return $this->store->get('homepage_entry');
    }

    public function defaultPerPage(): int
    {
        return (int) $this->value('default_per_page');
    }

    public function maxPerPage(): int
    {
        return (int) $this->value('max_per_page');
    }

    public function cacheTtl(): int
    {
        return (int) $this->value('cache_ttl');
    }

    public function schedulerEnabled(): bool
    {
        return (bool) $this->value('scheduler_enabled');
    }

    public function webhooksEnabled(): bool
    {
        return (bool) $this->value('webhooks_enabled');
    }

    /**
     * The REQUESTED `thallo.search` capability state, delegated to the one switchboard
     * (CapabilityStateStore): canonical system key → legacy row → config map → enabled.
     */
    public function searchEnabled(): bool
    {
        return $this->capabilityState->requested('thallo.search');
    }

    /**
     * The effective settings (for the admin General page).
     *
     * @return array<string,mixed>
     */
    public function all(): array
    {
        $out = [];
        foreach (array_keys(self::DEFS) as $key) {
            $out[$key] = $this->value($key);
        }

        return $out;
    }

    /**
     * Persist the supplied settings (only keys present and non-null are written).
     *
     * @param array<string,mixed> $partial
     */
    public function save(array $partial): void
    {
        $pairs = [];
        foreach (self::DEFS as $key => [$cfg, $type, $def]) {
            if (array_key_exists($key, $partial) && $partial[$key] !== null) {
                // homepage_entry (homepage-setting spec §0) and theme
                // (theme-setting spec §1): an EXPLICIT empty string means
                // "clear to fallback" — the row is DELETED so the config/.env
                // value shows through (a stored '' would shadow it).
                // null keeps the usual "unchanged" meaning.
                if (in_array($key, ['homepage_entry', 'theme'], true) && $partial[$key] === '') {
                    $this->store->forget($key);
                    continue;
                }
                // The search switch is capability state, not a settings row: it goes through
                // the switchboard (which also retires the legacy search_enabled system key).
                if ($key === 'search_enabled') {
                    $this->capabilityState->put('thallo.search', (bool) $partial[$key]);
                    continue;
                }
                $pairs[$key] = $this->encode($partial[$key], $type);
            }
        }
        $this->store->putMany($pairs);
    }

    private function value(string $key): mixed
    {
        [$cfg, $type, $def] = self::DEFS[$key];
        // The search switch reads through the one switchboard authority.
        if ($key === 'search_enabled') {
            return $this->capabilityState->requested('thallo.search');
        }
        $raw = $this->store->get($key);
        if ($raw === null) {
            // No override stored — fall back to the deploy-time config/.env value.
            return config($this->context, $cfg, $def);
        }

        return $this->decode($raw, $type);
    }

    private function decode(string $raw, string $type): mixed
    {
        return match ($type) {
            'int' => (int) $raw,
            'bool' => in_array(strtolower($raw), ['1', 'true', 'on', 'yes'], true),
            'list' => $raw === '' ? [] : array_values(array_filter(array_map(trim(...), explode(',', $raw)))),
            default => $raw,
        };
    }

    private function encode(mixed $value, string $type): string
    {
        return match ($type) {
            'int' => (string) (int) $value,
            'bool' => $value ? 'true' : 'false',
            'list' => implode(',', array_values(array_filter(array_map(
                static fn(mixed $v): string => trim((string) $v),
                (array) $value,
            )))),
            default => trim((string) $value),
        };
    }
}
