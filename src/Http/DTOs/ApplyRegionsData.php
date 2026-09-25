<?php

declare(strict_types=1);

namespace Thallo\Core\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * POST /admin/regions/preview/apply body: the session token, BOTH regions as the stage should
 * show them, and the compare-and-set pair (regions-stage spec §4.3). RegionValidator owns the
 * region rules, so its errors carry `regions.{slug}.` dot paths.
 */
final class ApplyRegionsData implements RequestData
{
    public function __construct(
        #[Rule('required|string')]
        public readonly string $token = '',
        /** @var array<string, array{blocks?: list<array<string,mixed>>, settings?: array<string,mixed>}> */
        #[Rule('array')]
        public readonly array $regions = [],
        #[Rule('nullable|string')]
        public readonly ?string $epoch = null,
        #[Rule('nullable|integer')]
        public readonly ?int $base_revision = null,
        /** @var list<array<string,mixed>> */
        #[Rule('nullable|array')]
        public readonly ?array $operations = null,
    ) {
    }
}
