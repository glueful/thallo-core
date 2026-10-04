<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Fields;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Contracts\Fields\FieldOptionSourceRegistry;
use Thallo\Core\Content\Authorization\PermissionRequirementAuthority;

/**
 * `GET /v1/admin/field-options/{source}`: the choices for a block field that names `{source}` as its
 * `options_source` (search block spec §3.9). Only registered sources resolve; each runs in the
 * server's workspace under its own permission, checked after the route's `content.edit` — so the
 * editor gets a field's choices without the right to administer what they describe.
 */
final class FieldOptionsController
{
    public function __construct(
        private readonly FieldOptionSourceRegistry $sources,
        private readonly ApplicationContext $context,
    ) {
    }

    public function show(Request $request, string $source): Response
    {
        $found = $this->sources->find($source);
        if ($found === null) {
            return Response::notFound('Unknown option source.');
        }
        if (!(new PermissionRequirementAuthority($this->context))->allows($request, [$found->permission()])) {
            return Response::error('Forbidden', Response::HTTP_FORBIDDEN, ['code' => 'FORBIDDEN']);
        }
        return Response::success(['options' => $found->options()]);
    }
}
