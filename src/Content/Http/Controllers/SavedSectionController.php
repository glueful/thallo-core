<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\Controllers;

use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Contracts\Authorization\PermissionRequirementAuthority;
use Thallo\Contracts\Layouts\LayoutSurfaceRegistry;
use Thallo\Core\Content\Http\DTOs\SaveSectionData;
use Thallo\Core\Content\Http\DTOs\UpdateSavedSectionData;
use Thallo\Core\Content\Layouts\LayoutFieldLabels;
use Thallo\Core\Content\Layouts\LayoutValidator;
use Thallo\Core\Content\Patterns\PatternLibrary;
use Thallo\Core\Content\Patterns\SavedSectionRepository;
use Thallo\Core\Content\Regions\RegionDefinitions;
use Thallo\Core\Content\Schema\ContentTypeSchema;
use Thallo\Core\Content\Style\Classes\StyleClassArchived;
use Thallo\Core\Content\Style\Classes\StyleClassLocked;
use Thallo\Core\Content\Style\Classes\StyleClassReferenceGuard;
use Thallo\Core\Content\Validation\FieldValidator;
use Thallo\Core\Content\Validation\ValidationException;
use Thallo\Core\Support\ActorHelper;

/**
 * Saved sections (the Blocks tab's library): a block saved from the stage and reused as a copy.
 * The library lists them with the shipped sections (`GET /patterns`); these save, rename and
 * delete them. A saved block is validated as a page save would validate it and stored without ids.
 * One saved from the header or footer belongs there: its region must take its block at the root.
 * One saved from a layout belongs to that kind of layout (sections and templates design §5): it keeps
 * its field blocks, checked and normalised against the layout it came from.
 *
 * Who may change one depends on where it belongs: a layout's needs `templates.manage` (the layouts'
 * permission), a page's or region's `content.manage`. Saving asks for the scope given; renaming and
 * deleting ask for the stored section's own.
 */
final class SavedSectionController
{
    private const MAX_NAME = 120;
    private const MAX_CATEGORY = 60;
    private const MAX_DESCRIPTION = 500;
    public const DEFAULT_CATEGORY = 'Saved';

    public function __construct(
        private readonly SavedSectionRepository $sections,
        private readonly PatternLibrary $library,
        private readonly FieldValidator $validator,
        private readonly ?StyleClassReferenceGuard $classGuard = null,
        /** Who may change a section of a scope; null refuses every change. */
        private readonly ?PermissionRequirementAuthority $authority = null,
        /** The layout surfaces a `layout` section may name. */
        private readonly ?LayoutSurfaceRegistry $surfaces = null,
        /** A `layout` section's rules: its surface's, for the layout it is saved from. */
        private readonly ?LayoutValidator $layouts = null,
        /** What the fields a `layout` section shows are called where it is saved from. */
        private readonly ?LayoutFieldLabels $fieldLabels = null,
        /** The palette fence (custom palette spec §4.3); null = unfenced. */
        private readonly ?\Thallo\Core\Content\Palette\PaletteFence $fence = null,
        private readonly ?\Thallo\Core\Content\Palette\PaletteNormalizer $normalizer = null,
    ) {
    }

    /** POST /v1/admin/saved-sections */
    #[ApiOperation(
        summary: 'Save a section',
        description: 'Saves one block — with everything inside it — to the Blocks tab\'s library. The '
            . 'block is validated as a page save would validate it and stored without ids. Body: '
            . '`name` (required, up to 120 characters), `block`, and optionally `category` (default '
            . '"Saved"), `description`, and where it belongs: `scope` `page` (the default), '
            . '`region` with `region` `header` or `footer`, whose palette must take the block, or '
            . '`layout` with its `surface` and the `target` of the layout it is saved from — checked '
            . 'against that layout\'s rules, its field blocks kept with the bindings they have there. '
            . 'Requires `templates.manage` for a layout\'s section, `content.manage` for any other.',
        tags: ['Thallo Admin'],
    )]
    #[ApiResponse(201, description: 'Saved; the library entry.')]
    #[ApiResponse(403, description: 'The caller may not change sections of that scope.')]
    #[ApiResponse(
        422,
        description: 'A missing name, a block a page save would refuse, one its region does not take, or '
            . 'one its layout does not.',
    )]
    public function store(SaveSectionData $input, Request $request): Response
    {
        $scope = $input->scope === null || $input->scope === '' ? 'page' : $input->scope;
        if (!$this->may($request, $scope)) {
            return self::forbidden();
        }
        [$labels, $errors] = $this->labels($input->name, $input->category, $input->description, true);
        [$region, $surface, $placeErrors] = $this->place(
            $scope,
            $input->region,
            $input->surface,
            $input->target,
            $input->block,
        );
        $errors += $placeErrors;
        if ($errors !== []) {
            return Response::validation($errors);
        }
        if ($surface !== null && $this->layouts !== null) {
            $checked = $this->layouts->fragment($surface, (string) $input->target, [$input->block]);
            if ($checked['errors'] !== []) {
                return Response::validation(self::asBlockErrors($checked['errors']));
            }
            $cleanBlock = (array) ($checked['blocks'][0] ?? []);
        } else {
            try {
                $clean = $this->validator->validate(
                    ContentTypeSchema::fromArray([['name' => 'blocks', 'type' => 'blocks']]),
                    ['blocks' => [$input->block]],
                    true,
                );
            } catch (ValidationException $e) {
                return Response::validation(self::asBlockErrors($e->errors()));
            }
            $cleanBlock = (array) ($clean['blocks'][0] ?? []);
        }
        $block = self::withoutIds($cleanBlock);
        // A layout section names its fields by the labels they have here: offered where a field is
        // missing, the other type has no label for it.
        $shows = $surface === null
            ? null
            : $this->fieldLabels?->ofBlocks($surface, (string) $input->target, [$block]);
        // As a page save: no class that is archived, or held by a job rewriting every document. Through
        // the palette fence (custom palette spec §4.3): a new section has no trusted basis.
        $store = function (array $block) use ($labels, $region, $request, $surface, $shows): string {
            $this->classGuard?->assertBlocksWritable([], [$block]);
            return $this->sections->create(
                (string) $labels['name'],
                $labels['category'] ?? self::DEFAULT_CATEGORY,
                $labels['description'] ?? null,
                $block,
                $region,
                ActorHelper::uuidFromRequest($request),
                $surface,
                $shows,
            );
        };
        try {
            $id = $this->fence === null || $this->normalizer === null
                ? $store($block)
                : $this->fence->write(
                    fn (\Thallo\Core\Content\Palette\PaletteSnapshot $s): \Thallo\Core\Content\Palette\Normalized
                        => $this->normalizer->normalize(
                            \Thallo\Core\Content\Palette\ColorTokenWalker::KIND_SECTION,
                            ['blocks' => [$block]],
                            $s,
                            [],
                        ),
                    fn (array $doc): string => $store((array) ($doc['blocks'][0] ?? $block)),
                );
        } catch (\Thallo\Core\Content\Palette\PaletteRefusal $e) {
            return \Thallo\Core\Content\Palette\PaletteRefusalResponse::from($e);
        } catch (StyleClassLocked $e) {
            return Response::error('A job holds a style class this section applies.', Response::HTTP_CONFLICT, [
                'code' => 'STYLE_CLASS_LOCKED',
                'job' => $e->job,
                'style_class' => $e->id,
            ]);
        } catch (StyleClassArchived $e) {
            return Response::validation(['block' => $e->getMessage()]);
        }
        return Response::created(['section' => $this->entry($id)], 'Section saved.');
    }

    /** PATCH /v1/admin/saved-sections/{id} */
    #[ApiOperation(
        summary: 'Rename a saved section',
        description: 'Changes a saved section\'s `name`, `category` or `description`. Requires '
            . '`templates.manage` for a layout\'s section, `content.manage` for any other.',
        tags: ['Thallo Admin'],
    )]
    #[ApiResponse(200, description: 'Renamed; the library entry.')]
    #[ApiResponse(403, description: 'The caller may not change sections of its scope.')]
    #[ApiResponse(404, description: 'No such saved section.')]
    public function update(UpdateSavedSectionData $input, string $id, Request $request): Response
    {
        $row = $this->sections->find($id);
        if ($row === null) {
            return Response::notFound('No such saved section.');
        }
        if (!$this->may($request, $row['scope'])) {
            return self::forbidden();
        }
        [$labels, $errors] = $this->labels($input->name, $input->category, $input->description, false);
        if ($errors !== []) {
            return Response::validation($errors);
        }
        $this->sections->update($id, $labels);
        return Response::success(['section' => $this->entry($id)], 'Section renamed.');
    }

    /** DELETE /v1/admin/saved-sections/{id} */
    #[ApiOperation(
        summary: 'Delete a saved section',
        description: 'Removes a saved section from the library. Pages and layouts that inserted it keep '
            . 'their copies. Requires `templates.manage` for a layout\'s section, `content.manage` for any '
            . 'other.',
        tags: ['Thallo Admin'],
    )]
    #[ApiResponse(200, description: 'Deleted.')]
    #[ApiResponse(403, description: 'The caller may not change sections of its scope.')]
    #[ApiResponse(404, description: 'No such saved section.')]
    public function destroy(string $id, Request $request): Response
    {
        $row = $this->sections->find($id);
        if ($row === null) {
            return Response::notFound('No such saved section.');
        }
        if (!$this->may($request, $row['scope'])) {
            return self::forbidden();
        }
        return $this->sections->delete($id)
            ? Response::success(null, 'Section deleted.')
            : Response::notFound('No such saved section.');
    }

    /** Whether the caller may change a section of `$scope`: a layout's is the layouts' permission. */
    private function may(Request $request, string $scope): bool
    {
        $needs = $scope === 'layout' ? 'templates.manage' : 'content.manage';
        return $this->authority?->allows($request, [$needs]) ?? false;
    }

    private static function forbidden(): Response
    {
        return Response::error('Forbidden', Response::HTTP_FORBIDDEN, ['code' => 'FORBIDDEN']);
    }

    /**
     * @param array<string,string> $errors keyed by the one-block document's paths
     * @return array<string,string> keyed by the saved block's (`block…`)
     */
    private static function asBlockErrors(array $errors): array
    {
        $out = [];
        foreach ($errors as $path => $message) {
            $out[(string) preg_replace('~\Ablocks\.0~', 'block', (string) $path)] = $message;
        }
        return $out;
    }

    /**
     * Where a section belongs: a page body; the region's slug — whose palette must take the block at
     * its root, as the region's own save would insist; or a layout surface this site has, with the
     * target of the layout it is saved from.
     *
     * @param array<string,mixed> $block
     * @return array{0: ?string, 1: ?string, 2: array<string,string>} the region, the surface, the errors
     */
    private function place(string $scope, ?string $region, ?string $surface, ?string $target, array $block): array
    {
        if ($scope === 'page') {
            return [null, null, []];
        }
        if ($scope === 'layout') {
            if ($surface === null || $surface === '' || $this->surfaces?->get($surface) === null) {
                return [null, null, ['surface' => "unknown layout surface '{$surface}'"]];
            }
            if ($target === null || $target === '') {
                return [null, null, ['target' => 'is required for a layout\'s section']];
            }
            return [null, $surface, []];
        }
        if ($scope !== 'region') {
            return [null, null, ['scope' => 'must be page, region or layout']];
        }
        $palette = RegionDefinitions::PALETTES[(string) $region] ?? null;
        if ($palette === null) {
            return [null, null, ['region' => 'must be one of: ' . implode(', ', RegionDefinitions::slugs())]];
        }
        $type = $block['type'] ?? null;
        if (!is_string($type) || !in_array($type, $palette, true)) {
            $label = is_string($type) ? $type : '?';
            return [null, null, ['block.type' => "'{$label}' is not allowed in the {$region} region"]];
        }
        return [(string) $region, null, []];
    }

    /**
     * The name, category and description as given: trimmed, and within their lengths.
     *
     * @return array{0: array{name?: string, category?: string, description?: ?string}, 1: array<string,string>}
     */
    private function labels(?string $name, ?string $category, ?string $description, bool $nameRequired): array
    {
        $out = [];
        $errors = [];
        if ($name !== null || $nameRequired) {
            $name = trim((string) $name);
            if ($name === '') {
                $errors['name'] = 'is required';
            } elseif (mb_strlen($name) > self::MAX_NAME) {
                $errors['name'] = 'must be at most ' . self::MAX_NAME . ' characters';
            } else {
                $out['name'] = $name;
            }
        }
        if ($category !== null) {
            $category = trim($category);
            if (mb_strlen($category) > self::MAX_CATEGORY) {
                $errors['category'] = 'must be at most ' . self::MAX_CATEGORY . ' characters';
            } else {
                $out['category'] = $category === '' ? self::DEFAULT_CATEGORY : $category;
            }
        }
        if ($description !== null) {
            $description = trim($description);
            if (mb_strlen($description) > self::MAX_DESCRIPTION) {
                $errors['description'] = 'must be at most ' . self::MAX_DESCRIPTION . ' characters';
            } else {
                $out['description'] = $description === '' ? null : $description;
            }
        }
        return [$out, $errors];
    }

    /** @return array<string,mixed>|null the section as the library lists it, from its stored row */
    private function entry(string $id): ?array
    {
        $row = $this->sections->find($id);
        return $row === null ? null : $this->library->savedEntry($row);
    }

    /**
     * @param array<string,mixed> $block
     * @return array<string,mixed> the tree with every id removed
     */
    private static function withoutIds(array $block): array
    {
        unset($block['id']);
        foreach ((array) ($block['data'] ?? []) as $key => $value) {
            $isBlockList = is_array($value) && array_is_list($value) && $value !== []
                && is_array($value[0]) && isset($value[0]['type']);
            if ($isBlockList) {
                $block['data'][$key] = array_map(static fn (array $child): array => self::withoutIds($child), $value);
            }
        }
        return $block;
    }
}
