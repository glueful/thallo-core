<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs\Responses\Publication;

use Glueful\Http\Contracts\ResponseData;

/**
 * Doc-only schema holder: the rollback response names the version ACTUALLY pinned
 * (block-migrations spec §5) — the requested one on the plain re-pin path, or the
 * NEWLY MATERIALIZED one when block-migration projection changed the fields. It
 * therefore carries the pinned version number too, unlike publish's bare
 * {version_uuid}. NEVER constructed at runtime — OpenAPI reflection only.
 */
final class RollbackResultData implements ResponseData
{
    public function __construct(
        public readonly string $version_uuid,
        public readonly int $version,
    ) {
    }
}
