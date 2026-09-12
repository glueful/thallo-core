<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs\Responses\ContentTypes;

use Glueful\Http\Contracts\ResponseData;

/**
 * Doc-only schema holder: the success-envelope `data` payload returned by any endpoint
 * that resolves a single content type (store, show, updateSchema).
 * NEVER constructed at runtime — it exists only so the OpenAPI generator can reflect a
 * typed schema for the `content_type` wrapper object (see {@see ContentTypeData}).
 */
final class ContentTypeResultData implements ResponseData
{
    public function __construct(
        public readonly ContentTypeData $content_type,
    ) {
    }
}
