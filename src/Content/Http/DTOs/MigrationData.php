<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

final class MigrationData implements RequestData
{
    /** @param list<array<string,mixed>> $ops */
    public function __construct(
        #[Rule('required|array')]
        public readonly array $ops,
    ) {
    }
}
