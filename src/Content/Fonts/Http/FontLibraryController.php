<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Fonts\Http;

use Glueful\Database\Connection;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Thallo\Contracts\Fonts\FontFamilyView;
use Thallo\Core\Content\Fonts\FontId;
use Thallo\Core\Content\Fonts\FontLibrary;
use Thallo\Core\Content\Fonts\FontLibraryRefusal;
use Thallo\Core\Content\Fonts\FontUsage;
use Thallo\Core\Content\Fonts\Http\DTOs\AddFontFaceData;
use Thallo\Core\Content\Fonts\Http\DTOs\CreateFontFamilyData;
use Thallo\Core\Content\Fonts\Http\DTOs\UpdateFontFamilyData;
use Thallo\Core\Content\Fonts\UnreadableFont;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Content\Authorization\PermissionRequirementAuthority;
use Thallo\Render\ThemeLocator;

/**
 * The font library API (block typeface spec §2, §4.6). The picker read — built-ins, then every
 * uploaded family (removed ones labelled, so a stored value can be named) and the theme's face — is
 * open to any editor; it carries no usage. Usage and every change need `content.manage`
 * (routes/admin.php). A file the reader refuses is a 422 naming the file and the reason.
 */
final class FontLibraryController
{
    /** The built-in typefaces, picker order, with their display names. */
    private const BUILT_INS = [
        'theme' => 'Theme', 'serif' => 'Serif', 'humanist' => 'Humanist', 'geometric' => 'Geometric',
        'slab' => 'Slab', 'mono' => 'Mono', 'system' => 'System',
    ];

    public function __construct(
        private readonly FontLibrary $library,
        private readonly FontUsage $usage,
        private readonly Connection $db,
        /** The active theme: its optional face (spec §2.2) is the Theme built-in's specimen. */
        private readonly ?ThemeLocator $theme = null,
        /** Whether the reader may manage the library: the picker offers Restore only then. */
        private readonly ?PermissionRequirementAuthority $permissions = null,
    ) {
    }

    /** GET /v1/admin/fonts */
    #[ApiOperation(
        summary: 'List typefaces',
        description: 'The typefaces an editor can choose: the built-ins, then the workspace\'s uploaded '
            . 'families (removed ones flagged) with the faces read from their files, and the active '
            . 'theme\'s own face, and whether the reader may manage the library (`can_manage`). No usage. '
            . 'Requires any of `content.edit`, `content.manage`, `templates.manage` or `styles.manage`.',
        tags: ['Thallo Fonts'],
    )]
    #[ApiResponse(200, description: 'The typefaces.')]
    public function index(?Request $request = null): Response
    {
        $families = [];
        foreach (self::BUILT_INS as $id => $name) {
            $families[] = ['id' => $id, 'name' => $name, 'kind' => 'builtin', 'fallback' => null,
                'removed' => false, 'faces' => []];
        }
        $uploaded = [];
        foreach ($this->db->table('font_families')->select(['id'])->get() as $row) {
            $view = $this->family((string) $row['id']);
            if ($view !== null) {
                $uploaded[] = self::present($view);
            }
        }
        usort($uploaded, static fn (array $a, array $b): int => [$a['removed'], strtolower($a['name']), $a['id']]
            <=> [$b['removed'], strtolower($b['name']), $b['id']]);
        return Response::success([
            'families' => [...$families, ...$uploaded],
            'theme_face' => $this->themeFace(),
            'can_manage' => $request !== null && $this->permissions?->allows($request, ['content.manage']) === true,
        ], 'Typefaces retrieved.');
    }

    /** GET /v1/admin/fonts/{id}/usage */
    #[ApiOperation(
        summary: 'Where a typeface is used',
        description: 'Every page draft and publication, header and footer, layout, saved section, style class '
            . 'and Appearance assignment that names the typeface. Requires `content.manage`.',
        tags: ['Thallo Fonts'],
    )]
    #[ApiResponse(200, description: 'The usage.')]
    public function usage(string $id): Response
    {
        if (!FontId::isValid($id)) {
            return Response::notFound('That typeface doesn\'t exist');
        }
        return Response::success($this->usage->of($id), 'Usage retrieved.');
    }

    /** POST /v1/admin/fonts */
    #[ApiOperation(
        summary: 'Add a family',
        description: 'A family from one or more media library `.woff2` files, each read for its weight, '
            . 'style and weight range. Requires `content.manage`.',
        tags: ['Thallo Fonts'],
    )]
    #[ApiResponse(201, description: 'The family.')]
    #[ApiResponse(422, description: 'A file could not be read, or the name or fallback is missing.')]
    public function store(CreateFontFamilyData $input): Response
    {
        $blobs = array_values(array_filter($input->blob_uuids, 'is_string'));
        return $this->attempt('blob_uuids', function () use ($input, $blobs): Response {
            $id = $this->library->create($input->name, $input->fallback, $blobs);
            return Response::created(['family' => $this->presentId($id)], 'Family added.');
        });
    }

    /** PATCH /v1/admin/fonts/{id} */
    #[ApiOperation(summary: 'Rename a family or change its fallback', tags: ['Thallo Fonts'])]
    #[ApiResponse(200, description: 'The family.')]
    public function update(UpdateFontFamilyData $input, string $id): Response
    {
        return $this->attempt('name', function () use ($input, $id): Response {
            if ($input->name !== null) {
                $this->library->rename($id, $input->name);
            }
            if ($input->fallback !== null) {
                $this->library->setFallback($id, $input->fallback);
            }
            return Response::success(['family' => $this->presentId($id)], 'Family saved.');
        });
    }

    /** POST /v1/admin/fonts/{id}/faces */
    #[ApiOperation(summary: 'Add a file to a family', tags: ['Thallo Fonts'])]
    #[ApiResponse(200, description: 'The family.')]
    public function addFace(AddFontFaceData $input, string $id): Response
    {
        return $this->attempt('blob_uuid', function () use ($input, $id): Response {
            $this->library->addFace($id, $input->blob_uuid);
            return Response::success(['family' => $this->presentId($id)], 'File added.');
        });
    }

    /** DELETE /v1/admin/fonts/{id}/faces/{blob_uuid} */
    #[ApiOperation(summary: 'Remove a file from a family', tags: ['Thallo Fonts'])]
    #[ApiResponse(200, description: 'The family.')]
    public function removeFace(string $id, string $blobUuid): Response
    {
        return $this->attempt('blob_uuid', function () use ($id, $blobUuid): Response {
            $this->library->removeFace($id, $blobUuid);
            return Response::success(['family' => $this->presentId($id)], 'File removed.');
        });
    }

    /** DELETE /v1/admin/fonts/{id} */
    #[ApiOperation(
        summary: 'Remove a family',
        description: 'The soft delete: the family leaves the pickers and the stylesheet; its files stay '
            . 'protected, so Restore brings it back whole. Requires `content.manage`.',
        tags: ['Thallo Fonts'],
    )]
    #[ApiResponse(200, description: 'The family.')]
    public function destroy(string $id): Response
    {
        return $this->attempt('id', function () use ($id): Response {
            $this->library->remove($id);
            return Response::success(['family' => $this->presentId($id)], 'Family removed.');
        });
    }

    /** POST /v1/admin/fonts/{id}/restore */
    #[ApiOperation(summary: 'Restore a removed family', tags: ['Thallo Fonts'])]
    #[ApiResponse(200, description: 'The family.')]
    public function restore(string $id): Response
    {
        return $this->attempt('id', function () use ($id): Response {
            $this->library->restore($id);
            return Response::success(['family' => $this->presentId($id)], 'Family restored.');
        });
    }

    /** DELETE /v1/admin/fonts/{id}/permanent */
    #[ApiOperation(
        summary: 'Delete a removed family permanently',
        description: 'Releases its files to the media library; what still names it renders as unknown. '
            . 'Requires `content.manage`.',
        tags: ['Thallo Fonts'],
    )]
    #[ApiResponse(200, description: 'Deleted.')]
    public function purge(string $id): Response
    {
        return $this->attempt('id', function () use ($id): Response {
            $this->library->purge($id);
            return Response::success(['deleted' => true], 'Family deleted.');
        });
    }

    /** POST /v1/admin/fonts/{id}/read-again */
    #[ApiOperation(
        summary: 'Read a family\'s files again',
        description: 'Replaces what was stored about each face with what its file says now; a face the '
            . 'upgrade could not read becomes known. Requires `content.manage`.',
        tags: ['Thallo Fonts'],
    )]
    #[ApiResponse(200, description: 'The family.')]
    public function readAgain(string $id): Response
    {
        return $this->attempt('faces', function () use ($id): Response {
            $this->library->readAgain($id);
            return Response::success(['family' => $this->presentId($id)], 'Files read again.');
        });
    }

    /**
     * Runs a library change, mapping its refusals: a missing family 404, a rule the library's state
     * forbids 409, anything the form should correct (and an unreadable file, named) 422.
     *
     * @param callable(): Response $change
     */
    private function attempt(string $field, callable $change): Response
    {
        try {
            return $change();
        } catch (UnreadableFont $e) {
            // The file it is about, by the name it was uploaded as, and why.
            $name = $e->blobUuid !== null
                ? $this->db->table('blobs')->select(['name'])->where('uuid', '=', $e->blobUuid)->first()['name'] ?? null
                : null;
            $message = is_string($name) && $name !== '' ? "{$name}: {$e->reason}" : $e->reason;
            return Response::validation([$field => $message], $message);
        } catch (FontLibraryRefusal $e) {
            return match ($e->kind) {
                'missing' => Response::notFound($e->getMessage()),
                'conflict' => Response::error($e->getMessage(), 409),
                default => Response::validation([$field => $e->getMessage()], $e->getMessage()),
            };
        }
    }

    private function family(string $id): ?FontFamilyView
    {
        return FontId::isValid($id) ? $this->library->snapshot()->family($id) : null;
    }

    /** @return array<string, mixed> */
    private function presentId(string $id): array
    {
        $view = $this->family($id);
        if ($view === null) {
            throw FontLibraryRefusal::missing();
        }
        return self::present($view);
    }

    /** @return array<string, mixed> */
    private static function present(FontFamilyView $family): array
    {
        return [
            'id' => $family->id,
            'name' => $family->name,
            'kind' => 'uploaded',
            'fallback' => $family->fallback,
            'removed' => $family->removed,
            'faces' => $family->faces,
        ];
    }

    /**
     * The active theme's face (spec §2.2): whether it declares one, its family, and the files the
     * Theme specimen loads — only those that exist.
     *
     * @return array{declared: bool, family: ?string, files: list<array{url: string, weight: string, style: string}>}
     */
    private function themeFace(): array
    {
        $face = null;
        $assets = null;
        try {
            $face = $this->theme?->vocabulary()->face();
            $assets = $this->theme?->activePaths()['assets'] ?? null;
        } catch (\Throwable) {
            $face = null;
        }
        if ($face === null) {
            return ['declared' => false, 'family' => null, 'files' => []];
        }
        $files = [];
        foreach ($face['files'] as $file) {
            if ($assets !== null && is_file($assets . '/' . $file['src'])) {
                $files[] = [
                    'url' => '/theme-assets/' . $file['src'],
                    'weight' => $file['weight'],
                    'style' => $file['style'],
                ];
            }
        }
        return ['declared' => true, 'family' => $face['family'], 'files' => $files];
    }
}
