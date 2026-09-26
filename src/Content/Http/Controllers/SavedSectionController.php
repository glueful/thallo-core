<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\Controllers;

use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Content\Http\DTOs\SaveSectionData;
use Thallo\Core\Content\Http\DTOs\UpdateSavedSectionData;
use Thallo\Core\Content\Patterns\PatternLibrary;
use Thallo\Core\Content\Patterns\SavedSectionRepository;
use Thallo\Core\Content\Regions\RegionDefinitions;
use Thallo\Core\Content\Schema\ContentTypeSchema;
use Thallo\Core\Content\Validation\FieldValidator;
use Thallo\Core\Content\Validation\ValidationException;
use Thallo\Core\Support\ActorHelper;

/**
 * Saved sections (the Blocks tab's library): a block saved from the stage and reused as a copy.
 * The library lists them with the shipped sections (`GET /patterns`); these save, rename and
 * delete them. A saved block is validated as a page save would validate it and stored without ids.
 * One saved from the header or footer belongs there: its region must take its block at the root.
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
    ) {
    }

    /** POST /v1/admin/saved-sections */
    #[ApiOperation(
        summary: 'Save a section',
        description: 'Saves one block — with everything inside it — to the Blocks tab\'s library. The '
            . 'block is validated as a page save would validate it and stored without ids. Body: '
            . '`name` (required, up to 120 characters), `block`, and optionally `category` (default '
            . '"Saved"), `description`, and where it belongs: `scope` `page` (the default) or '
            . '`region` with `region` `header` or `footer`, whose palette must take the block. '
            . 'Requires `content.manage`.',
        tags: ['Thallo Admin'],
    )]
    #[ApiResponse(201, description: 'Saved; the library entry.')]
    #[ApiResponse(
        422,
        description: 'A missing name, a block a page save would refuse, or one its region does not take.',
    )]
    public function store(SaveSectionData $input, Request $request): Response
    {
        [$labels, $errors] = $this->labels($input->name, $input->category, $input->description, true);
        [$region, $placeErrors] = self::place($input->scope, $input->region, $input->block);
        $errors += $placeErrors;
        if ($errors !== []) {
            return Response::validation($errors);
        }
        try {
            $clean = $this->validator->validate(
                ContentTypeSchema::fromArray([['name' => 'blocks', 'type' => 'blocks']]),
                ['blocks' => [$input->block]],
                true,
            );
        } catch (ValidationException $e) {
            $errors = [];
            foreach ($e->errors() as $path => $message) {
                $errors[preg_replace('~\Ablocks\.0~', 'block', (string) $path)] = $message;
            }
            return Response::validation($errors);
        }
        $block = self::withoutIds($clean['blocks'][0] ?? []);
        $id = $this->sections->create(
            (string) $labels['name'],
            $labels['category'] ?? self::DEFAULT_CATEGORY,
            $labels['description'] ?? null,
            $block,
            $region,
            ActorHelper::uuidFromRequest($request),
        );
        return Response::created(['section' => $this->entry($id)], 'Section saved.');
    }

    /** PATCH /v1/admin/saved-sections/{id} */
    #[ApiOperation(
        summary: 'Rename a saved section',
        description: 'Changes a saved section\'s `name`, `category` or `description`. Requires '
            . '`content.manage`.',
        tags: ['Thallo Admin'],
    )]
    #[ApiResponse(200, description: 'Renamed; the library entry.')]
    #[ApiResponse(404, description: 'No such saved section.')]
    public function update(UpdateSavedSectionData $input, string $id): Response
    {
        if (!$this->sections->exists($id)) {
            return Response::notFound('No such saved section.');
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
        description: 'Removes a saved section from the library. Pages that inserted it keep their '
            . 'copies. Requires `content.manage`.',
        tags: ['Thallo Admin'],
    )]
    #[ApiResponse(200, description: 'Deleted.')]
    #[ApiResponse(404, description: 'No such saved section.')]
    public function destroy(string $id): Response
    {
        return $this->sections->delete($id)
            ? Response::success(null, 'Section deleted.')
            : Response::notFound('No such saved section.');
    }

    /**
     * Where a section belongs: null for a page body, or the region's slug — whose palette must
     * take the block at its root, as the region's own save would insist.
     *
     * @param array<string,mixed> $block
     * @return array{0: ?string, 1: array<string,string>}
     */
    private static function place(?string $scope, ?string $region, array $block): array
    {
        $scope = $scope === null || $scope === '' ? 'page' : $scope;
        if ($scope === 'page') {
            return [null, []];
        }
        if ($scope !== 'region') {
            return [null, ['scope' => 'must be page or region']];
        }
        $palette = RegionDefinitions::PALETTES[(string) $region] ?? null;
        if ($palette === null) {
            return [null, ['region' => 'must be one of: ' . implode(', ', RegionDefinitions::slugs())]];
        }
        $type = $block['type'] ?? null;
        if (!is_string($type) || !in_array($type, $palette, true)) {
            $label = is_string($type) ? $type : '?';
            return [null, ['block.type' => "'{$label}' is not allowed in the {$region} region"]];
        }
        return [(string) $region, []];
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

    /** @return array<string,mixed>|null the section as the library lists it */
    private function entry(string $id): ?array
    {
        foreach ($this->library->all() as $pattern) {
            if (($pattern['id'] ?? null) === $id) {
                return $pattern;
            }
        }
        return null;
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
