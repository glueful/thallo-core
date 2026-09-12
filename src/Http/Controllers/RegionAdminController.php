<?php

declare(strict_types=1);

namespace Thallo\Core\Http\Controllers;

use Thallo\Core\Content\Regions\RegionDefinitions;
use Thallo\Core\Content\Regions\RegionRepository;
use Thallo\Core\Content\Regions\RegionValidator;
use Thallo\Core\Content\Validation\ValidationException;
use Thallo\Core\Http\DTOs\PreviewRegionsData;
use Thallo\Core\Http\DTOs\UpdateRegionData;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Events\EventService;
use Glueful\Http\Response;
use Thallo\Contracts\Content\RegionUpdated;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\TwigFactory;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Global chrome regions admin (global-regions spec): the header/footer block
 * lists + fixed settings. Palettes ship in every read so the SPA picker
 * filters without hardcoding; saves are palette/schema/vocabulary validated
 * (RegionValidator) and broad-purge the render page cache via RegionUpdated.
 * Gated `content.manage` — chrome is content policy, not Twig editing.
 */
final class RegionAdminController
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly RegionRepository $regions,
        private readonly RegionValidator $validator,
    ) {
    }

    /** GET /v1/admin/regions */
    #[ApiOperation(
        summary: 'List chrome regions',
        description: 'Every global region (header, footer) with its saved blocks, settings, allowed '
            . 'block palette and settings keys. Absent rows surface as empty lists so the editor '
            . 'always round-trips. Requires `content.view`.',
        tags: ['Thallo Regions'],
    )]
    #[ApiResponse(200, description: 'Regions with palettes.')]
    public function index(): Response
    {
        $out = [];
        foreach (RegionDefinitions::slugs() as $slug) {
            $row = $this->regions->find($slug);
            $out[] = [
                'slug' => $slug,
                'blocks' => $row['blocks'] ?? [],
                'settings' => $row['settings'] ?? (object) [],
                'palette' => RegionDefinitions::PALETTES[$slug],
                'settings_keys' => RegionDefinitions::SETTINGS_KEYS[$slug],
            ];
        }
        return Response::success(['regions' => $out], 'Regions retrieved.');
    }

    /** PUT /v1/admin/regions/{slug} */
    #[ApiOperation(
        summary: 'Save a chrome region',
        description: 'Replaces the region\'s block list and settings. Blocks are validated against '
            . 'their block-type schemas AND the region\'s server-enforced palette (out-of-palette '
            . 'types 422 with dot paths); settings are a fixed vocabulary. Applies immediately and '
            . 'purges the render page cache. Requires `content.manage`.',
        tags: ['Thallo Regions'],
    )]
    #[ApiResponse(200, description: 'Region saved.')]
    #[ApiResponse(404, description: 'Unknown region slug.')]
    #[ApiResponse(422, description: 'Out-of-palette block, schema violation, or unknown setting.')]
    public function update(UpdateRegionData $input, string $slug): Response
    {
        if (!in_array($slug, RegionDefinitions::slugs(), true)) {
            return Response::notFound('Unknown region.');
        }

        // RegionValidator throws ValidationException (ValidationFailed) → 422 with dot paths.
        $clean = $this->validator->validate($slug, $input->blocks, $input->settings);
        $this->regions->save($slug, $clean['blocks'], $clean['settings'], null);

        // Chrome appears on every page: broad-purge the render page cache (spec §11).
        app($this->context, EventService::class)->dispatch(new RegionUpdated($slug));

        return Response::success([
            'region' => [
                'slug' => $slug,
                'blocks' => $clean['blocks'],
                'settings' => $clean['settings'] === [] ? (object) [] : $clean['settings'],
                'palette' => RegionDefinitions::PALETTES[$slug],
                'settings_keys' => RegionDefinitions::SETTINGS_KEYS[$slug],
            ],
        ], 'Region saved.');
    }

    /** POST /v1/admin/regions/preview */
    #[ApiOperation(
        summary: 'Preview chrome regions',
        description: 'Renders the POSTED (unsaved) header/footer block lists through the real theme '
            . 'pipeline and returns a self-contained HTML document for an iframe. Validates exactly '
            . 'like a save (palette, schemas, settings) so errors surface BEFORE anything goes live. '
            . 'Never writes. Requires `content.view`.',
        tags: ['Thallo Regions'],
    )]
    #[ApiResponse(200, description: 'Rendered preview document.')]
    #[ApiResponse(409, description: 'Render pack unavailable.')]
    #[ApiResponse(422, description: 'Same validation a save would fail.')]
    public function preview(PreviewRegionsData $input, Request $request): Response
    {
        $container = container($this->context);
        if (!$container->has(TwigFactory::class)) {
            return Response::error('Preview unavailable: the render pack is not active.', 409);
        }

        // Validate posted payloads with save-identical rules; prefix error paths per slug.
        $context = [];
        foreach (RegionDefinitions::slugs() as $slug) {
            $posted = $input->regions[$slug] ?? null;
            if (is_array($posted)) {
                try {
                    $clean = $this->validator->validate(
                        $slug,
                        is_array($posted['blocks'] ?? null) ? array_values($posted['blocks']) : [],
                        is_array($posted['settings'] ?? null) ? $posted['settings'] : [],
                    );
                } catch (ValidationException $e) {
                    $prefixed = [];
                    foreach ($e->errors() as $path => $message) {
                        $prefixed["regions.{$slug}.{$path}"] = $message;
                    }
                    throw new ValidationException($prefixed);
                }
                // A posted-but-empty region previews as absent (the null rule's spirit).
                $context[$slug] = $clean['blocks'] === [] ? null
                    : ['blocks' => $clean['blocks'], 'settings' => $clean['settings']];
            } else {
                $saved = $this->regions->find($slug);
                $context[$slug] = ($saved === null || $saved['blocks'] === []) ? null
                    : ['blocks' => $saved['blocks'], 'settings' => $saved['settings']];
            }
        }

        // Mirror RenderController's reset-family discipline: the extension is a
        // shared singleton; admin preview must not leak state into page renders.
        $ext = $container->get(RenderContextExtension::class);
        $ext->setLocale((string) config($this->context, 'i18n.default_locale', 'en'));
        $ext->resetPerRenderState();
        $ext->resetTags();
        $ext->setAssetContext(null, null);

        // Absolute base (P1 pin): the SPA loads this document from a blob: URL,
        // where host-relative asset paths don't resolve — the <base> anchors
        // them to the real origin, so CSS loads exactly like the live page.
        $context['base_href'] = $request->getSchemeAndHttpHost() . '/';

        $html = $container->get(TwigFactory::class)->environment()->render('region-preview.twig', $context);

        return Response::success(['html' => self::withoutNoscriptFallbacks($html)], 'Preview rendered.');
    }

    /**
     * Drop `<noscript>` fallbacks from the preview document.
     *
     * The SPA frames this HTML with `sandbox="allow-same-origin"` and deliberately WITHOUT
     * `allow-scripts` (granting both to same-origin content would defeat the sandbox, and
     * blocks can carry operator-authored markup). Scripting is therefore off inside the
     * frame, so the browser renders every block's no-JS fallback — a stray "View cart"
     * under the mini-cart, "Browse products" under a product grid — none of which a visitor
     * ever sees on the real, scripted page. Stripping them here (one place, so new blocks
     * cannot drift) makes the preview show the shell state the operator is actually
     * arranging. Enhancement-dependent CONTENT (live cart counts, hydrated grids) still
     * cannot appear in a script-free frame; that is inherent to the isolation choice.
     */
    private static function withoutNoscriptFallbacks(string $html): string
    {
        // `<noscript>` never nests, so a non-greedy element match is exact here.
        return (string) preg_replace('#<noscript\b[^>]*>.*?</noscript>#is', '', $html);
    }
}
