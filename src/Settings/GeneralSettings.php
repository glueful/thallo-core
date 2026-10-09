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
        'default_locale'    => ['i18n.default_locale', 'string', 'en'],
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
        // Design settings (website plan phase 1b): closed enums, defaults are today's look.
        'theme_radius'      => ['thallo.theme.radius', 'string', 'round'],
        'theme_font'        => ['thallo.theme.font', 'string', 'sans'],
        // The `custom` pairing's Text and Headings (block typeface spec §2.8): font library IDs — a
        // built-in or an uploaded family. '' is stored when someone clears one, so the one-time
        // upgrade from the old uploads (FontLibraryUpgrade) never refills it.
        'theme_font_text_family'     => ['thallo.theme.font_text_family', 'string', ''],
        'theme_font_headings_family' => ['thallo.theme.font_headings_family', 'string', ''],
        'theme_background'  => ['thallo.theme.background', 'string', 'plain'],
        // The palette (custom palette spec §2): JSON values, '' when unset; read by PaletteSettings.
        'theme_neutral_custom' => ['thallo.theme.neutral_custom', 'string', ''],
        'theme_dark_base'   => ['thallo.theme.dark_base', 'string', ''],
        'theme_brand_1'     => ['thallo.theme.brand_1', 'string', ''],
        'theme_brand_2'     => ['thallo.theme.brand_2', 'string', ''],
        'theme_brand_3'     => ['thallo.theme.brand_3', 'string', ''],
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
        /** The default language's home; null without the i18n extension (config applies). */
        private readonly ?\Glueful\Extensions\I18n\Services\LocaleManager $locales = null,
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

    public function themeRadius(): string
    {
        return (string) $this->value('theme_radius');
    }

    public function themeFont(): string
    {
        return (string) $this->value('theme_font');
    }

    /** The `custom` pairing's Text family (a font library ID); '' when none. */
    public function themeFontTextFamily(): string
    {
        return (string) $this->value('theme_font_text_family');
    }

    /** The `custom` pairing's Headings family (a font library ID); '' when none. */
    public function themeFontHeadingsFamily(): string
    {
        return (string) $this->value('theme_font_headings_family');
    }

    /**
     * A stored settings row as it is now, read fresh: null when there is no row, '' when an empty
     * value is stored. Unlike the accessors, it never falls back to a default — it tells "never set"
     * apart from "deliberately cleared".
     */
    public function storedValue(string $key): ?string
    {
        $this->store->clearCache();
        return $this->store->get($key);
    }

    /** The raw stored value of a key, '' when no row: the palette keys have no config fallback worth reading. */
    public function stored(string $key): string
    {
        return (string) ($this->store->get($key) ?? '');
    }

    /** Drop the store's read cache, so the next read sees another request's commit. */
    public function clearStoreCache(): void
    {
        $this->store->clearCache();
    }

    public function themeBackground(): string
    {
        return (string) $this->value('theme_background');
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
                // The palette's JSON keys the same way (custom palette spec §2.1): '' is Reset.
                $clearable = [
                    'homepage_entry', 'theme',
                    'theme_neutral_custom', 'theme_brand_1', 'theme_brand_2', 'theme_brand_3',
                ];
                if (in_array($key, $clearable, true) && $partial[$key] === '') {
                    $this->store->forget($key);
                    continue;
                }
                // The default locale IS the default language: no copy of it is stored here.
                if ($key === 'default_locale' && $this->locales !== null) {
                    $this->makeDefaultLanguage((string) $partial[$key]);
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

    /** Makes an enabled language the site's default; any other code is refused. */
    private function makeDefaultLanguage(string $code): void
    {
        $row = $this->locales?->find($code);
        if (!is_array($row) || !(bool) ($row['enabled'] ?? false)) {
            throw new \InvalidArgumentException("'{$code}' is not an enabled language, so it cannot be the default.");
        }
        $this->locales?->update($code, ['is_default' => true]);
        $this->store->forget('default_locale');
    }

    private function value(string $key): mixed
    {
        [$cfg, $type, $def] = self::DEFS[$key];
        // The default locale is the default language (Settings › Languages), not a row of its own.
        if ($key === 'default_locale' && $this->locales !== null) {
            return $this->locales->default();
        }
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
