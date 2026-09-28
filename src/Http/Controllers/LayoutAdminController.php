<?php

declare(strict_types=1);

namespace Thallo\Core\Http\Controllers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Contracts\Layouts\LayoutSurfaceRegistry;
use Thallo\Contracts\Style\StyleClassProvider;
use Thallo\Core\Content\Layouts\LayoutRepository;
use Thallo\Core\Content\Layouts\LayoutTargets;
use Thallo\Core\Content\Layouts\LayoutSaver;
use Thallo\Core\Content\Layouts\LayoutVersionConflict;
use Thallo\Core\Content\Preview\LayoutPreviewStore;
use Thallo\Core\Content\Preview\LayoutPreviewToken;
use Thallo\Core\Content\Preview\PreviewTokenException;
use Thallo\Core\Content\Preview\ResolvesPreviewKey;
use Thallo\Core\Content\Style\Classes\StyleClassArchived;
use Thallo\Core\Content\Style\Classes\StyleClassLocked;
use Thallo\Core\Content\Validation\ValidationException;
use Thallo\Core\Http\DTOs\RemoveLayoutData;
use Thallo\Core\Http\DTOs\SaveLayoutData;
use Thallo\Core\Support\ActorHelper;

/**
 * The Layouts page and a layout's Save and Remove (type layouts spec §5.5, §6.1). Save and Remove
 * come from the layout editor only: each names the editing session it advances by its token, and
 * {@see LayoutSaver} holds the contract.
 */
final class LayoutAdminController
{
    use ResolvesPreviewKey;

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly LayoutSurfaceRegistry $surfaces,
        private readonly LayoutRepository $layouts,
        private readonly LayoutSaver $saver,
        private readonly LayoutPreviewStore $store,
        private readonly ?StyleClassProvider $styleClasses = null,
        /** Whether the caller may open the editor (the list is `content.view`; editing `templates.manage`). */
        private readonly ?\Thallo\Contracts\Authorization\PermissionRequirementAuthority $authority = null,
        /** The targets as the site can use them; null reads the surface's own. */
        private readonly ?LayoutTargets $targets = null,
    ) {
    }

    /** GET /v1/admin/layouts */
    #[ApiOperation(
        summary: 'List the layouts',
        description: 'Every page kind that can have a layout — for each content type, its single entry, '
            . 'its listing pages and each archived field\'s archive pages — with whether it has one '
            . '(`custom`) or renders through the theme (`theme`), who saved it, and when a target cannot '
            . 'have one, why (`reason`) and the admin page that puts it right (`link`). A layout kept for '
            . 'pages no longer on the site is listed too, closed, so it can be removed. `can_edit` says '
            . 'whether the caller may open the editor (`templates.manage`). Requires `content.view`.',
        tags: ['Thallo Layouts'],
    )]
    #[ApiResponse(200, description: 'The layouts.')]
    public function index(Request $request): Response
    {
        $rows = [];
        $saved = [];
        foreach ($this->surfaces->all() as $surface) {
            foreach ($this->targets?->of($surface) ?? $surface->targets() as $target) {
                $row = $this->layouts->find($surface->key(), $target['target']);
                $saved[] = $row['updated_by'] ?? null;
                $rows[] = [
                    'surface' => $surface->key(),
                    'target' => $target['target'],
                    'label' => $surface->label($target['target']),
                    'reach' => $surface->reach($target['target']),
                    'state' => $row !== null && $row['blocks'] !== null ? 'custom' : 'theme',
                    'enabled' => $target['enabled'],
                    'reason' => $target['reason'],
                    'link' => $target['link'],
                    'lock_version' => $row['lock_version'] ?? 0,
                    'updated_by' => $row['updated_by'] ?? null,
                    'updated_at' => $row['updated_at'] ?? null,
                ];
            }
        }
        $names = $this->namesOf(array_values(array_unique(array_filter($saved, 'is_string'))));
        foreach ($rows as $i => $row) {
            // Who saved it, only while it is a custom layout: a removal is not an authorship.
            $by = $row['state'] === 'custom' && is_string($row['updated_by']) ? $row['updated_by'] : null;
            $rows[$i]['updated_by_name'] = $by === null ? null : ($names[$by] ?? null);
        }
        return Response::success([
            'layouts' => $rows,
            'can_edit' => $this->authority?->allows($request, ['templates.manage']) ?? false,
        ], 'Layouts retrieved.');
    }

    /**
     * The username — else the email — of each user who saved a layout, read in one query.
     *
     * @param list<string> $uuids
     * @return array<string,string>
     */
    private function namesOf(array $uuids): array
    {
        if ($uuids === []) {
            return [];
        }
        $names = [];
        foreach (db($this->context)->table('users')->whereIn('uuid', $uuids)->get() as $user) {
            $name = is_string($user['username'] ?? null) && $user['username'] !== ''
                ? $user['username']
                : (is_string($user['email'] ?? null) ? $user['email'] : null);
            if ($name !== null) {
                $names[(string) $user['uuid']] = $name;
            }
        }
        return $names;
    }

    /** GET /v1/admin/layouts/{surface}/{target}/samples */
    #[ApiOperation(
        summary: 'List a layout\'s samples',
        description: 'The published items a layout can be previewed against, newest first, up to 50, '
            . 'optionally filtered by `q`. Requires `templates.manage`.',
        tags: ['Thallo Layouts'],
    )]
    #[ApiResponse(200, description: 'The samples.')]
    public function samples(Request $request, string $surface, string $target): Response
    {
        $kind = $this->surfaces->get($surface);
        if ($kind === null) {
            return Response::notFound('Unknown layout surface.');
        }
        $query = $request->query->get('q');
        return Response::success([
            'samples' => $kind->samples($target, is_string($query) && $query !== '' ? $query : null),
            'default' => $kind->defaultSample($target),
        ], 'Samples retrieved.');
    }

    /** PUT /v1/admin/layouts/{surface}/{target} */
    #[ApiOperation(
        summary: 'Save a layout',
        description: 'Writes the layout if its version is still the one the editor loaded, then installs '
            . 'it as the editing session\'s baseline, clears the session\'s working copy on the exact '
            . 'revision pair, and purges the pages it renders. Requires `templates.manage`.',
        tags: ['Thallo Layouts'],
    )]
    #[ApiResponse(200, description: 'Saved; the layout as committed.')]
    #[ApiResponse(409, description: 'Someone saved or removed it since (LAYOUT_VERSION_CONFLICT).')]
    #[ApiResponse(410, description: 'The editing session expired or its layout was removed.')]
    #[ApiResponse(422, description: 'The layout is invalid.')]
    public function save(SaveLayoutData $input, Request $request, string $surface, string $target): Response
    {
        $this->styleClasses?->refresh();
        $claims = $this->claims($input->token, $surface, $target);
        if ($claims instanceof Response) {
            return $claims;
        }
        $pair = $input->preview_revision;
        $pair = is_array($pair) && is_string($pair['epoch'] ?? null) && is_int($pair['revision'] ?? null)
            ? ['epoch' => $pair['epoch'], 'revision' => $pair['revision']]
            : null;
        try {
            $saved = $this->saver->save(
                $claims,
                is_array($input->layout['blocks'] ?? null) ? array_values($input->layout['blocks']) : [],
                is_array($input->layout['settings'] ?? null) ? $input->layout['settings'] : [],
                $input->expected_lock_version,
                $pair,
                ActorHelper::uuidFromRequest($request),
            );
        } catch (LayoutVersionConflict $e) {
            return self::conflict($e);
        } catch (ValidationException $e) {
            return Response::validation($e->errors());
        } catch (StyleClassLocked $e) {
            return Response::error('A job holds a style class this save applies.', Response::HTTP_CONFLICT, [
                'code' => 'STYLE_CLASS_LOCKED',
                'job' => $e->job,
                'style_class' => $e->id,
            ]);
        } catch (StyleClassArchived $e) {
            return Response::validation(['blocks' => $e->getMessage()]);
        }
        return Response::success($saved, 'Layout saved.');
    }

    /** DELETE /v1/admin/layouts/{surface}/{target} */
    #[ApiOperation(
        summary: 'Remove a layout',
        description: 'Removes the layout if its version is still the one the editor loaded: the pages '
            . 'it rendered return to the theme\'s template, and the editing session that removed it '
            . 'ends. Requires `templates.manage`.',
        tags: ['Thallo Layouts'],
    )]
    #[ApiResponse(200, description: 'Removed; the tombstone\'s version.')]
    #[ApiResponse(409, description: 'Someone saved or removed it since (LAYOUT_VERSION_CONFLICT).')]
    public function destroy(RemoveLayoutData $input, Request $request, string $surface, string $target): Response
    {
        $claims = $this->claims($input->token, $surface, $target);
        if ($claims instanceof Response) {
            return $claims;
        }
        try {
            $by = ActorHelper::uuidFromRequest($request);
            $version = $this->saver->remove($claims, $input->expected_lock_version, $by);
        } catch (LayoutVersionConflict $e) {
            return self::conflict($e);
        }
        return Response::success(['lock_version' => $version], 'Layout removed.');
    }

    /** The editing session this request advances: a layout token for this surface and target. */
    private function claims(string $token, string $surface, string $target): LayoutPreviewToken|Response
    {
        try {
            $claims = LayoutPreviewToken::verify($token, $this->previewKey($this->context), time());
        } catch (PreviewTokenException $e) {
            return $e->isExpired()
                ? Response::error('The editing session expired.', 410, ['code' => 'LAYOUT_SESSION_EXPIRED'])
                : Response::forbidden('Invalid preview token');
        }
        if ($claims->surface !== $surface || $claims->target !== $target) {
            return Response::forbidden('Invalid preview token');
        }
        if ($this->store->isRetired($claims->session)) {
            return Response::error('This layout was removed.', 410, ['code' => 'LAYOUT_SESSION_RETIRED']);
        }
        if ($this->store->baseline($claims->session) === null) {
            return Response::error('The editing session expired.', 410, ['code' => 'LAYOUT_SESSION_EXPIRED']);
        }
        return $claims;
    }

    private static function conflict(LayoutVersionConflict $e): Response
    {
        return Response::error('This layout changed since it was loaded.', Response::HTTP_CONFLICT, [
            'code' => 'LAYOUT_VERSION_CONFLICT',
            'current' => $e->current,
        ]);
    }
}
