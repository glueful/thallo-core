<?php

declare(strict_types=1);

namespace Thallo\Core\Http\Controllers;

use Thallo\Contracts\Style\RegionStyle;
use Thallo\Core\Content\Regions\RegionDefinitions;
use Thallo\Core\Content\Regions\RegionRepository;
use Thallo\Core\Content\Regions\RegionValidator;
use Thallo\Core\Content\Validation\ValidationException;
use Thallo\Core\Http\DTOs\UpdateRegionData;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Events\EventService;
use Glueful\Http\Response;
use Thallo\Contracts\Content\RegionUpdated;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;

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
        /** The site's style classes (visual builder spec §4.3): refreshed first, one snapshot per request. */
        private readonly ?\Thallo\Contracts\Style\StyleClassProvider $styleClasses = null,
        private readonly ?\Thallo\Core\Content\Regions\RegionSaver $saver = null,
        private readonly ?\Thallo\Core\Content\Preview\RegionPreviewStore $previews = null,
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
                'style_capabilities' => RegionStyle::CAPABILITIES,
                // What a save names as expected (regions-stage spec §4.5); null = no row yet.
                'lock_version' => $row['lock_version'] ?? null,
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
        $this->styleClasses?->refresh();
        if (!in_array($slug, RegionDefinitions::slugs(), true)) {
            return Response::notFound('Unknown region.');
        }

        // Both regions' versions as loaded are required: the same serialized section as the batch
        // save checks them and validates the complete candidate (regions-stage spec §4.5).
        if (!is_array($input->expected) || !self::namesBoth($input->expected)) {
            return Response::validation(['expected' => 'both regions\' versions are required']);
        }
        $result = $this->runSave(
            [$slug => ['blocks' => $input->blocks, 'settings' => $input->settings]],
            $input->expected,
            $slug,
        );
        if ($result instanceof Response) {
            return $result;
        }
        $saved = $result[$slug];

        return Response::success([
            'region' => [
                'slug' => $slug,
                'blocks' => $saved['blocks'],
                'lock_version' => $saved['lock_version'],
                'settings' => $saved['settings'] === [] ? (object) [] : $saved['settings'],
                'palette' => RegionDefinitions::PALETTES[$slug],
                'settings_keys' => RegionDefinitions::SETTINGS_KEYS[$slug],
                'style_capabilities' => RegionStyle::CAPABILITIES,
            ],
        ], 'Region saved.');
    }

    /** PUT /v1/admin/regions */
    #[ApiOperation(
        summary: 'Save the header and footer',
        description: 'Writes the posted regions in one serialized section: both regions\' expected '
            . 'versions are checked (the unchanged one too), the complete candidate is validated, '
            . 'and every posted region is written or none is. From the stage it also advances the '
            . 'session baseline and clears the working copy on an exact revision pair. Requires '
            . '`content.manage`.',
        tags: ['Thallo Regions'],
    )]
    #[ApiResponse(200, description: 'Saved; both regions as committed.')]
    #[ApiResponse(409, description: 'A region changed since it was loaded (REGION_VERSION_CONFLICT).')]
    #[ApiResponse(422, description: 'The candidate is invalid, or the expected versions are missing.')]
    public function saveAll(\Thallo\Core\Http\DTOs\SaveRegionsData $input): Response
    {
        $this->styleClasses?->refresh();
        if (!is_array($input->expected) || !self::namesBoth($input->expected)) {
            return Response::validation(['expected' => 'both regions\' versions are required']);
        }
        $posted = [];
        foreach ($input->regions as $slug => $region) {
            if (!in_array($slug, RegionDefinitions::slugs(), true)) {
                return Response::validation(["regions.{$slug}" => "unknown region '{$slug}'"]);
            }
            $posted[$slug] = is_array($region) ? $region : [];
        }
        $committed = $this->runSave($posted, $input->expected);
        if ($committed instanceof Response) {
            return $committed;
        }

        $cleared = false;
        $session = $this->sessionOf($input->token);
        if ($session !== null && $this->previews !== null) {
            $this->previews->putBaseline($session['session'], $committed, $session['exp']);
            $pair = $input->preview_revision;
            if (is_array($pair) && is_string($pair['epoch'] ?? null) && is_int($pair['revision'] ?? null)) {
                $cleared = $this->previews->clearIfPair($session['session'], $pair['epoch'], $pair['revision']);
            }
        }

        return Response::success([
            'regions' => array_map(
                static fn (array $r): array => $r + ['settings' => []],
                $committed,
            ),
            'preview_cleared' => $cleared,
        ], 'Regions saved.');
    }

    /**
     * The serialized section, then the one cache purge (regions-stage spec §4.5).
     *
     * @param array<string, array<string,mixed>> $posted
     * @param array<string, ?int> $expected
     * @param ?string $unprefix the per-region endpoint's slug: its own errors keep their plain
     *                          `blocks.…` / `settings.…` paths, as that endpoint documents
     * @return array<string, array<string,mixed>>|Response the committed regions, or the error
     */
    private function runSave(array $posted, array $expected, ?string $unprefix = null): array|Response
    {
        $saver = $this->saver ?? new \Thallo\Core\Content\Regions\RegionSaver(
            db($this->context),
            $this->regions,
            $this->validator,
        );
        try {
            $committed = $saver->save($posted, $expected, null);
        } catch (\Thallo\Core\Content\Regions\RegionVersionConflict $e) {
            return Response::error('A region changed since it was loaded.', Response::HTTP_CONFLICT, [
                'code' => 'REGION_VERSION_CONFLICT',
                'moved' => $e->moved,
            ]);
        } catch (ValidationException $e) {
            $errors = [];
            $prefix = $unprefix === null ? null : "regions.{$unprefix}.";
            foreach ($e->errors() as $path => $message) {
                $key = $prefix !== null && str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
                $errors[$key] = $message;
            }
            return Response::validation($errors);
        } catch (\Thallo\Core\Content\Style\Classes\StyleClassLocked $e) {
            return Response::error('A job holds a style class this save applies.', Response::HTTP_CONFLICT, [
                'code' => 'STYLE_CLASS_LOCKED',
                'job' => $e->job,
                'style_class' => $e->id,
            ]);
        } catch (\Thallo\Core\Content\Style\Classes\StyleClassArchived $e) {
            return Response::validation(['blocks' => $e->getMessage()]);
        }
        // Chrome appears on every page: broad-purge the render page cache once (spec §11).
        app($this->context, EventService::class)->dispatch(new RegionUpdated(implode(',', array_keys($posted))));
        return $committed;
    }

    /** @param array<string,mixed> $expected */
    private static function namesBoth(array $expected): bool
    {
        foreach (RegionDefinitions::slugs() as $slug) {
            if (!array_key_exists($slug, $expected) || ($expected[$slug] !== null && !is_int($expected[$slug]))) {
                return false;
            }
        }
        return true;
    }

    /**
     * The stage session a save came from — its id and expiry — or null (not from the stage, or a
     * token that does not verify). The save's authority is the permission; the token only names
     * which session's baseline and working copy to update.
     *
     * @return array{session: string, exp: int}|null
     */
    private function sessionOf(?string $token): ?array
    {
        if ($token === null || $token === '') {
            return null;
        }
        $signer = new class ($this->context) {
            use \Thallo\Core\Content\Preview\ResolvesPreviewKey;

            public function __construct(private readonly ApplicationContext $context)
            {
            }

            public function key(): string
            {
                return $this->previewKey($this->context);
            }
        };
        try {
            $claims = \Thallo\Core\Content\Preview\RegionPreviewToken::verify($token, $signer->key(), time());
        } catch (\Thallo\Core\Content\Preview\PreviewTokenException) {
            return null;
        }
        return ['session' => $claims->session, 'exp' => $claims->expiresAt];
    }
}
