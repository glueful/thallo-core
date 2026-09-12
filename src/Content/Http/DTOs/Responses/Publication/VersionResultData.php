<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs\Responses\Publication;

use Glueful\Http\Contracts\ResponseData;

/**
 * Doc-only schema holder: mirrors the `{version_uuid}` object the runtime emits inside
 * the success envelope for publish/rollback. NEVER constructed at runtime — exists only
 * so the OpenAPI generator can reflect a typed schema. version_uuid is a 12-char nanoid
 * (nullable to allow the no-pinned-version case).
 */
final class VersionResultData implements ResponseData
{
    public function __construct(
        public readonly ?string $version_uuid,
    ) {
    }
}
