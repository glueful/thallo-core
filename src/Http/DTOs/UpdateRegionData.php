<?php

declare(strict_types=1);

namespace Thallo\Core\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * PUT /admin/regions/{slug} body. The blocks list and settings object are
 * free-form here — RegionValidator owns the real rules (palette, block
 * schemas, settings vocabulary) so errors carry dot paths.
 */
final class UpdateRegionData implements RequestData
{
    public function __construct(
        /** @var list<array<string,mixed>> Ordered {id,type,data} block list. */
        #[Rule('array')]
        public readonly array $blocks = [],
        /** @var array<string,mixed> Fixed per-region settings vocabulary. */
        #[Rule('array')]
        public readonly array $settings = [],
    ) {
    }
}
