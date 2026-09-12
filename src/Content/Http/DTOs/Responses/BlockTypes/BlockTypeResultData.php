<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs\Responses\BlockTypes;

use Glueful\Http\Contracts\ResponseData;

/**
 * Doc-only schema holder: the success-envelope `data` payload wrapping a single
 * block type (`block_type` key). NEVER constructed at runtime.
 */
final class BlockTypeResultData implements ResponseData
{
    public function __construct(
        public readonly BlockTypeItemData $block_type,
    ) {
    }
}
