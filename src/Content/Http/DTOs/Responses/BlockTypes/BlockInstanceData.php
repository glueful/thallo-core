<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs\Responses\BlockTypes;

use Glueful\Http\Contracts\ResponseData;

/**
 * Doc-only schema holder: the success-envelope `data` payload of the block factory
 * (`POST /block-types/{slug}/instance`). NEVER constructed at runtime.
 */
final class BlockInstanceData implements ResponseData
{
    public function __construct(
        /** The canonical fresh block: no id — the editor mints them. */
        public readonly BlockInstanceBlockData $block,
        /** @var array<string,mixed> The type's starter content, to merge over `block.data`. */
        public readonly array $starter,
    ) {
    }
}
