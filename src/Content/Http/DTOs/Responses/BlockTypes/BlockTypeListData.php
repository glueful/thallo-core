<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs\Responses\BlockTypes;

use Glueful\Http\Contracts\ResponseData;
use Glueful\Validation\Attributes\ArrayOf;

/**
 * Doc-only schema holder: the success-envelope `data` payload of the block-types
 * index endpoint. NEVER constructed at runtime.
 */
final class BlockTypeListData implements ResponseData
{
    /** @param list<BlockTypeItemData> $block_types */
    public function __construct(
        #[ArrayOf(BlockTypeItemData::class)]
        public readonly array $block_types,
    ) {
    }
}
