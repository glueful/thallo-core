<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs\Responses\ContentTypes;

use Glueful\Http\Contracts\ResponseData;
use Glueful\Validation\Attributes\ArrayOf;

/**
 * Doc-only schema holder: the success-envelope `data` payload returned by the index
 * endpoint, containing an array of every content type in the instance.
 * NEVER constructed at runtime — it exists only so the OpenAPI generator can reflect a
 * typed schema for the `content_types` array (see {@see ContentTypeData}).
 */
final class ContentTypeListData implements ResponseData
{
    /** @param list<ContentTypeData> $content_types */
    public function __construct(
        #[ArrayOf(ContentTypeData::class)]
        public readonly array $content_types,
    ) {
    }
}
