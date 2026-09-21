<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs\Responses\Patterns;

use Glueful\Http\Contracts\ResponseData;

/**
 * Doc-only schema holder: the success-envelope `data` payload of `GET /patterns`.
 * NEVER constructed at runtime.
 */
final class PatternListData implements ResponseData
{
    public function __construct(
        /** @var list<PatternData> Sections in their categories' order, then pages. */
        public readonly array $patterns,
    ) {
    }
}
