<?php

declare(strict_types=1);

namespace Thallo\Core\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * POST /admin/regions/preview body: per-slug UNSAVED region payloads. Free-form
 * here — RegionValidator owns the real rules per region so preview surfaces the
 * same 422s a save would, before anything goes live.
 */
final class PreviewRegionsData implements RequestData
{
    public function __construct(
        /** @var array<string, array{blocks?: list<array<string,mixed>>, settings?: array<string,mixed>}> */
        #[Rule('array')]
        public readonly array $regions = [],
    ) {
    }
}
