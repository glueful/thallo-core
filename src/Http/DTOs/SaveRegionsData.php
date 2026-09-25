<?php

declare(strict_types=1);

namespace Thallo\Core\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * PUT /admin/regions body (regions-stage spec §4.5): the dirty regions, BOTH regions' versions as
 * loaded, and — from the stage — its session token and the accepted pair the save was made from.
 */
final class SaveRegionsData implements RequestData
{
    public function __construct(
        /** @var array<string, array{blocks?: list<array<string,mixed>>, settings?: array<string,mixed>}> */
        #[Rule('array')]
        public readonly array $regions = [],
        /** @var array<string, ?int> every region's lock_version as loaded; null = no row yet */
        #[Rule('array')]
        public readonly ?array $expected = null,
        #[Rule('nullable|string')]
        public readonly ?string $token = null,
        /** @var array{epoch: string, revision: int}|null */
        #[Rule('nullable|array')]
        public readonly ?array $preview_revision = null,
    ) {
    }
}
