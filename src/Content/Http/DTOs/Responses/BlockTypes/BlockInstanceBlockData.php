<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs\Responses\BlockTypes;

use Glueful\Http\Contracts\ResponseData;

/**
 * Doc-only schema holder: the block half of {@see BlockInstanceData}. NEVER constructed at
 * runtime.
 */
final class BlockInstanceBlockData implements ResponseData
{
    public function __construct(
        public readonly string $type,
        /** @var array<string,mixed> Every blocks field `[]`, every enum field its first option. */
        public readonly array $data,
        /** @var array<string,mixed> Empty on a fresh block. */
        public readonly array $settings,
    ) {
    }
}
