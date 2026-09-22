<?php

declare(strict_types=1);

namespace Thallo\Core\Http\Controllers;

use Thallo\Core\Http\DTOs\Responses\GeneralSettingsResultData;
use Thallo\Core\Http\DTOs\UpdateGeneralSettingsData;
use Thallo\Core\Settings\GeneralSettings;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Events\EventService;
use Glueful\Http\Response;
use Glueful\Routing\RouteCache;
use Glueful\Routing\RouteManifest;
use Thallo\Contracts\Delivery\PreviewThemeValidator;
use Thallo\Contracts\Settings\ThemeAppearanceChanged;
use Thallo\Contracts\Style\StyleArtifactCompiler;
use Thallo\Contracts\Style\StyleCompileFailed;
use Thallo\Contracts\Settings\ThemeChanged;
use Thallo\Render\Theme\ThemeColors;
use Thallo\Render\Theme\ThemeDesign;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;

/**
 * Read/write the instance "General" settings — site identity, default locale, content-delivery
 * defaults, and feature toggles.
 *
 * Backed by the `settings` table via {@see GeneralSettings}: a stored row overrides the
 * deploy-time config/.env default, so a save takes effect on the next request across every instance
 * with no restart (unlike the `.env`-backed email settings). Gated by `content.manage` — see
 * routes/admin.php.
 */
final class GeneralSettingsController
{
    private const PER_PAGE_CAP = 1000;

    public function __construct(
        private readonly GeneralSettings $settings,
        private readonly ApplicationContext $context,
        private readonly ?\Thallo\Contracts\Delivery\PublicRouteResolver $resolver = null,
        private readonly ?\Thallo\Core\Content\Repositories\ContentTypeRepository $contentTypes = null,
        /** Soft-bound (theme-setting spec §1): null = render pack absent, theme is inert. */
        private readonly ?PreviewThemeValidator $themeValidator = null,
        private readonly ?EventService $events = null,
        /** Names the failing homepage condition; the resolver above stays the authority. */
        private readonly ?\Thallo\Core\Content\Delivery\HomepageEligibility $homepageEligibility = null,
        /** Soft-bound (visual builder spec §2.4): a theme switch compiles its artifact before activating. */
        private readonly ?StyleArtifactCompiler $styleCompiler = null,
    ) {
    }

    /** GET /v1/admin/settings/general */
    #[ApiOperation(
        summary: 'Get general settings',
        description: 'Effective instance settings (site identity, default locale, delivery defaults, '
            . 'feature toggles): a settings override, else the config/.env default. Requires '
            . '`content.manage`.',
        tags: ['Thallo Settings'],
    )]
    #[ApiResponse(200, schema: GeneralSettingsResultData::class, description: 'Current general settings.')]
    public function show(): Response
    {
        return Response::success(['settings' => $this->settings->all()], 'General settings retrieved.');
    }

    /**
     * The site's identity as every rendered page shows it.
     *
     * @return list<string>
     */
    private function identity(): array
    {
        return [
            $this->settings->siteLogo(),
            $this->settings->siteLogoDark(),
            $this->settings->siteFavicon(),
            $this->settings->siteName(),
        ];
    }

    /** PUT /v1/admin/settings/general */
    #[ApiOperation(
        summary: 'Update general settings',
        description: 'Persists the submitted settings to settings (only supplied fields change). '
            . 'Applies on the next request — no restart. Requires `content.manage`.',
        tags: ['Thallo Settings'],
    )]
    #[ApiResponse(200, schema: GeneralSettingsResultData::class, description: 'Settings saved.')]
    #[ApiResponse(422, description: 'Invalid value (non-positive page size, max < default, …).')]
    public function update(UpdateGeneralSettingsData $input): Response
    {
        $errors = $this->validate($input);
        if ($errors !== []) {
            return Response::validation($errors);
        }

        // Compile before activate (visual builder spec §2.4): the theme's compiled style artifact
        // is published before the theme is stored, so a theme that cannot compile never goes live.
        if ($input->theme !== null && $input->theme !== '' && $this->styleCompiler !== null) {
            try {
                $this->styleCompiler->compile($input->theme);
            } catch (StyleCompileFailed $e) {
                return Response::validation(['theme' => $e->getMessage()]);
            }
        }

        $themeBefore = $this->settings->themeOverride();
        $accentBefore = $this->settings->themeAccent();
        $neutralBefore = $this->settings->themeNeutral();
        $designBefore = [
            $this->settings->themeRadius(),
            $this->settings->themeFont(),
            $this->settings->themeBackground(),
        ];
        $identityBefore = $this->identity();
        $searchBefore = $this->settings->searchEnabled();

        if ($input->default_locale !== null && $input->default_locale !== $this->settings->defaultLocale()) {
            // Saved separately so a language that cannot be the default is refused before anything
            // else is written.
            try {
                $this->settings->save(['default_locale' => $input->default_locale]);
            } catch (\InvalidArgumentException $e) {
                return Response::validation(['default_locale' => $e->getMessage()]);
            }
        }

        $this->settings->save([
            'theme' => $input->theme,
            // The one spelling the stylesheet writes (#ABC → #aabbcc); validated above.
            'theme_accent' => $input->theme_accent === null
                ? null
                : ThemeColors::normalizeSiteAccent($input->theme_accent),
            'theme_neutral' => $input->theme_neutral,
            'theme_radius' => $input->theme_radius,
            'theme_font' => $input->theme_font,
            'theme_font_body' => $input->theme_font_body,
            'theme_font_display' => $input->theme_font_display,
            'theme_background' => $input->theme_background,
            'site_name' => $input->site_name,
            'site_preview_url' => $input->site_preview_url,
            'default_per_page' => $input->default_per_page,
            'max_per_page' => $input->max_per_page,
            'cache_ttl' => $input->cache_ttl,
            'scheduler_enabled' => $input->scheduler_enabled,
            'webhooks_enabled' => $input->webhooks_enabled,
            'search_enabled' => $input->search_enabled,
            'homepage_entry' => $input->homepage_entry,
            'site_logo' => $input->site_logo,
            'site_logo_dark' => $input->site_logo_dark,
            'site_favicon' => $input->site_favicon,
            'admin_url' => $input->admin_url,
            'listing_types' => $input->listing_types,
        ]);

        // ThemeChanged only when the STORED override actually changed (theme-
        // setting spec §5): the render pack purges its page cache on it.
        if ($input->theme !== null && $this->settings->themeOverride() !== $themeBefore) {
            $this->events?->dispatch(new ThemeChanged($this->settings->theme()));
        }

        // ThemeAppearanceChanged only when a STORED appearance value actually
        // changed (theme-color-config spec §7): the render pack purges its page
        // cache (page + error keys) on it.
        $designAfter = [
            $this->settings->themeRadius(),
            $this->settings->themeFont(),
            $this->settings->themeBackground(),
        ];
        if (
            ($input->theme_accent !== null && $this->settings->themeAccent() !== $accentBefore)
            || ($input->theme_neutral !== null && $this->settings->themeNeutral() !== $neutralBefore)
            || $designAfter !== $designBefore
            // What every page shows of the site but the cache key does not carry: without this a new
            // logo, favicon or name was served stale for up to render.cache_ttl. (A custom font file
            // is in the key already.)
            || $this->identity() !== $identityBefore
        ) {
            $this->events?->dispatch(new ThemeAppearanceChanged(
                $this->settings->themeAccent(),
                $this->settings->themeNeutral(),
            ));
        }

        // The search switch gates ROUTE REGISTRATION at boot (SearchServiceProvider only
        // registers /v1/search while the capability is on), and the compiled route cache is
        // keyed by route-file signatures — a capability flip changes no files, so the stale
        // cache would keep serving the old route set forever. Clear it when the effective
        // value actually changed; the next request boots with the new state.
        if ($input->search_enabled !== null && $this->settings->searchEnabled() !== $searchBefore) {
            (new RouteCache($this->context))->clear();
            RouteManifest::reset();
        }

        return Response::success(
            ['settings' => $this->settings->all()],
            'General settings saved.',
        );
    }

    /**
     * Cross-field validation against the effective (current + submitted) values.
     *
     * @return array<string,string>
     */
    private function validate(UpdateGeneralSettingsData $input): array
    {
        $errors = [];

        if ($input->default_per_page !== null && $input->default_per_page < 1) {
            $errors['default_per_page'] = 'Must be at least 1.';
        }
        if ($input->max_per_page !== null && ($input->max_per_page < 1 || $input->max_per_page > self::PER_PAGE_CAP)) {
            $errors['max_per_page'] = 'Must be between 1 and ' . self::PER_PAGE_CAP . '.';
        }
        if ($input->cache_ttl !== null && $input->cache_ttl < 0) {
            $errors['cache_ttl'] = 'Cannot be negative (0 disables caching).';
        }

        // max_per_page must stay ≥ default_per_page (check the effective values).
        $current = $this->settings->all();
        $effDefault = $input->default_per_page ?? (int) $current['default_per_page'];
        $effMax = $input->max_per_page ?? (int) $current['max_per_page'];
        if (!isset($errors['default_per_page'], $errors['max_per_page']) && $effMax < $effDefault) {
            $errors['max_per_page'] = 'Max per page must be greater than or equal to the default.';
        }

        // Homepage (homepage-setting spec §0): write-time validation, never a
        // runtime surprise — the uuid must resolve to PUBLISHED content of a
        // publicly delivered type RIGHT NOW. '' (clear) and null (unchanged)
        // skip the check.
        if (
            $input->homepage_entry !== null
            && $input->homepage_entry !== ''
            && ($this->resolver === null
                || ($this->resolver->resolveEntry($input->homepage_entry)['kind'] ?? null) !== 'content')
        ) {
            $reason = $this->homepageEligibility?->reasonNotEligible($input->homepage_entry);
            $errors['homepage_entry'] = 'must be a published entry of a publicly delivered content type'
                . ($reason !== null ? " — {$reason}" : '');
        }

        // Theme (theme-setting spec §1): write-time validation — you cannot
        // store a theme that doesn't exist or has a broken theme.json. '' (clear)
        // and null (unchanged) skip; validator unbound (render pack absent) skips
        // too — the value is inert without a renderer.
        if (
            $input->theme !== null
            && $input->theme !== ''
            && $this->themeValidator !== null
            && !$this->themeValidator->isValidTheme($input->theme)
        ) {
            $errors['theme'] = 'unknown theme';
        }

        // Theme appearance (theme-color-config spec §2): closed enums; null =
        // unchanged. An out-of-enum value can never be stored.
        // The accent is a family or the site's own brand colour as a hex; the neutral stays a
        // family — a whole grey scale cannot be derived from one colour.
        if ($input->theme_accent !== null && ThemeColors::normalizeSiteAccent($input->theme_accent) === null) {
            $errors['theme_accent'] = 'unknown accent color (a colour family, or a hex like #0a7c66)';
        }
        if ($input->theme_neutral !== null && ThemeColors::normalizeNeutral($input->theme_neutral) === null) {
            $errors['theme_neutral'] = 'unknown neutral color';
        }
        // Design settings (website plan phase 1b): the same closed-enum discipline.
        if ($input->theme_radius !== null && ThemeDesign::normalizeRadius($input->theme_radius) === null) {
            $errors['theme_radius'] = 'unknown radius';
        }
        if ($input->theme_font !== null && ThemeDesign::normalizeFont($input->theme_font) === null) {
            $errors['theme_font'] = 'unknown typeface pairing';
        }
        // The site's own faces are media library files, named by uuid ('' clears). The page
        // declares them by the URL the library serves, so only the uuid's shape is held here.
        foreach (['theme_font_body', 'theme_font_display'] as $face) {
            $uuid = $input->{$face};
            if ($uuid !== null && $uuid !== '' && ThemeDesign::normalizeFace($uuid) === null) {
                $errors[$face] = 'not a media library file';
            }
        }
        if ($input->theme_background !== null && ThemeDesign::normalizeBackground($input->theme_background) === null) {
            $errors['theme_background'] = 'unknown page background';
        }

        // A non-empty admin URL must be absolute http(s) — relative values
        // would resolve against the PUBLIC site in the preview bar.
        if (
            $input->admin_url !== null
            && $input->admin_url !== ''
            && preg_match('#\Ahttps?://#i', $input->admin_url) !== 1
        ) {
            $errors['admin_url'] = 'must be an absolute http(s) URL';
        }

        // Listing types must NAME REAL content types (typo protection); the
        // public/non-public state stays a render-time gate, not a write gate —
        // a listed non-public type is dormant until its flag flips.
        if ($input->listing_types !== null && $this->contentTypes !== null) {
            foreach ($input->listing_types as $slug) {
                if ($this->contentTypes->findBySlug((string) $slug) === null) {
                    $errors['listing_types'] = "unknown content type '{$slug}'";
                    break;
                }
            }
        }

        return $errors;
    }
}
