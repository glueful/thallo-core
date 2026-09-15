<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\Controllers;

use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Contracts\Style\StyleCapabilities;
use Thallo\Contracts\Style\StyleClassProvider;
use Thallo\Core\Content\Http\DTOs\Responses\StyleClasses\StyleClassListData;
use Thallo\Core\Content\Http\DTOs\Responses\StyleClasses\StyleClassResultData;
use Thallo\Core\Content\Http\DTOs\Responses\StyleClasses\StyleClassUsageData;
use Thallo\Core\Content\Http\DTOs\StyleClassData;
use Thallo\Core\Content\Http\DTOs\UpdateStyleClassData;
use Thallo\Core\Content\Style\Classes\StyleClassLocked;
use Thallo\Core\Content\Style\Classes\StyleClassNameTaken;
use Thallo\Core\Content\Style\Classes\StyleClassNotFound;
use Thallo\Core\Content\Style\Classes\StyleClassRepository;
use Thallo\Core\Content\Style\Classes\StyleClassUsage;
use Thallo\Core\Content\Style\Classes\StyleClassVersionConflict;
use Thallo\Core\Content\Style\SettingsValidator;
use Thallo\Core\Http\DTOs\ErrorResponse;

/**
 * Style classes (visual builder spec §4.1, §4.3, §4.5): site-owned, theme-independent records
 * a block composes through `settings.classes`. Read routes require `content.view`; mutating
 * routes require `styles.manage` (route middleware). The list is one snapshot and names its
 * generation; a write names the version it loaded and conflicts when the row moved on; delete
 * archives, so old revisions still restore.
 */
final class StyleClassController
{
    public function __construct(
        private readonly StyleClassRepository $classes,
        private readonly StyleClassProvider $provider,
        private readonly StyleClassUsage $usage,
        private readonly SettingsValidator $settings = new SettingsValidator(),
    ) {
    }

    #[ApiOperation(
        summary: 'List style classes',
        description: 'Every class of the site, archived included, from one snapshot; `generation` names it.',
        tags: ['Thallo Admin'],
    )]
    #[ApiResponse(200, schema: StyleClassListData::class, description: 'The site\'s style classes.')]
    public function index(Request $request): Response
    {
        $this->provider->refresh();
        $snapshot = $this->provider->snapshot();
        return Response::success([
            'generation' => $snapshot->generation,
            'style_classes' => $this->classes->all(),
        ], 'Style classes retrieved.');
    }

    #[ApiOperation(summary: 'Create a style class', tags: ['Thallo Admin'])]
    #[ApiResponse(201, schema: StyleClassResultData::class, description: 'Style class created.')]
    #[ApiResponse(422, schema: ErrorResponse::class, envelope: false, description: 'Name taken or invalid style.')]
    public function store(StyleClassData $input, Request $request): Response
    {
        [$style, $errors] = $this->style($input->style);
        if ($errors !== []) {
            return Response::validation($errors);
        }
        try {
            $class = $this->classes->create([
                'name' => $input->name,
                'description' => $input->description,
                'style' => $style,
            ]);
        } catch (StyleClassNameTaken $e) {
            return Response::validation(['name' => $e->getMessage()]);
        } catch (\InvalidArgumentException $e) {
            return Response::validation(['name' => $e->getMessage()]);
        }
        return Response::created(['style_class' => $class], 'Style class created.');
    }

    #[ApiOperation(summary: 'One style class', tags: ['Thallo Admin'])]
    #[ApiResponse(200, schema: StyleClassResultData::class, description: 'The style class.')]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'Unknown id.')]
    public function show(Request $request, string $id): Response
    {
        $class = $this->classes->find($id);
        if ($class === null) {
            return Response::notFound('Style class not found.');
        }
        return Response::success(['style_class' => $class], 'Style class retrieved.');
    }

    #[ApiOperation(
        summary: 'Update a style class',
        description: '`version` is the version the client loaded; a stale one is 409 '
            . '`STYLE_CLASS_VERSION_CONFLICT` carrying `current_version`. Only the keys present change. '
            . 'Saving changes published pages immediately.',
        tags: ['Thallo Admin'],
    )]
    #[ApiResponse(200, schema: StyleClassResultData::class, description: 'Style class updated.')]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'Unknown id.')]
    #[ApiResponse(409, schema: ErrorResponse::class, envelope: false, description: 'Stale version or locked.')]
    #[ApiResponse(422, schema: ErrorResponse::class, envelope: false, description: 'Name taken or invalid style.')]
    public function update(UpdateStyleClassData $input, Request $request, string $id): Response
    {
        $changes = [];
        if ($input->name !== null) {
            $changes['name'] = $input->name;
        }
        if ($input->description !== null) {
            $changes['description'] = $input->description;
        }
        if ($input->style !== null) {
            [$style, $errors] = $this->style($input->style);
            if ($errors !== []) {
                return Response::validation($errors);
            }
            $changes['style'] = $style;
        }
        try {
            $class = $this->classes->update($id, $input->version, $changes);
        } catch (StyleClassNotFound) {
            return Response::notFound('Style class not found.');
        } catch (StyleClassVersionConflict $e) {
            return Response::error('The style class was saved by someone else first.', Response::HTTP_CONFLICT, [
                'code' => 'STYLE_CLASS_VERSION_CONFLICT',
                'current_version' => $e->currentVersion,
            ]);
        } catch (StyleClassLocked $e) {
            return self::locked($e);
        } catch (StyleClassNameTaken | \InvalidArgumentException $e) {
            return Response::validation(['name' => $e->getMessage()]);
        }
        return Response::success(['style_class' => $class], 'Style class updated.');
    }

    #[ApiOperation(
        summary: 'Archive a style class',
        description: 'Deletion archives the definition so old revisions still restore (spec §4.5). '
            . 'With `?unreferenced=1` a class nothing references is deleted outright — the editor uses it '
            . 'for a lift that could not preserve appearance; a referenced class answers 409 '
            . '`STYLE_CLASS_REFERENCED`.',
        tags: ['Thallo Admin'],
    )]
    #[ApiResponse(200, schema: StyleClassResultData::class, description: 'Style class archived or deleted.')]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'Unknown id.')]
    #[ApiResponse(409, schema: ErrorResponse::class, envelope: false, description: 'Locked or referenced.')]
    public function destroy(Request $request, string $id): Response
    {
        if ($request->query->getBoolean('unreferenced')) {
            $class = $this->classes->find($id);
            if ($class === null) {
                return Response::notFound('Style class not found.');
            }
            $references = $this->usage->of($id, $class['style'])['references'];
            if ($references > 0) {
                return Response::error('The style class is referenced; archive it instead.', Response::HTTP_CONFLICT, [
                    'code' => 'STYLE_CLASS_REFERENCED',
                    'references' => $references,
                ]);
            }
            try {
                $this->classes->deleteUnreferenced($id);
            } catch (StyleClassNotFound) {
                return Response::notFound('Style class not found.');
            }
            return Response::success(['style_class' => $class + ['deleted' => true]], 'Style class deleted.');
        }
        try {
            $class = $this->classes->archive($id);
        } catch (StyleClassNotFound) {
            return Response::notFound('Style class not found.');
        } catch (StyleClassLocked $e) {
            return self::locked($e);
        }
        return Response::success(['style_class' => $class], 'Style class archived.');
    }

    #[ApiOperation(
        summary: 'Where a style class is used',
        description: 'One reference per occurrence in one stored document across drafts, published '
            . 'entries, retained versions and regions (the published revision counted once); per '
            . 'property, active where the block has the capability and dormant elsewhere.',
        tags: ['Thallo Admin'],
    )]
    #[ApiResponse(200, schema: StyleClassUsageData::class, description: 'Usage counts.')]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'Unknown id.')]
    public function usage(Request $request, string $id): Response
    {
        $class = $this->classes->find($id);
        if ($class === null) {
            return Response::notFound('Style class not found.');
        }
        return Response::success(['usage' => $this->usage->of($id, $class['style'])], 'Usage retrieved.');
    }

    /**
     * A class's style is validated against every property: it declares no capabilities (§4.1).
     *
     * @param array<string,mixed> $style
     * @return array{0: array<string,mixed>, 1: array<string,string>}
     */
    private function style(array $style): array
    {
        [$clean, $errors] = $this->settings->validate(['style' => $style], StyleCapabilities::all());
        $out = [];
        foreach ($errors as $path => $message) {
            $out[preg_replace('/^settings\./', '', $path) ?? $path] = $message;
        }
        return [$clean['style'] ?? [], $out];
    }

    private static function locked(StyleClassLocked $e): Response
    {
        return Response::error('A job holds this style class until it completes.', Response::HTTP_CONFLICT, [
            'code' => 'STYLE_CLASS_LOCKED',
            'job' => $e->job,
        ]);
    }
}
