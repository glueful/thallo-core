<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

final class AssignRouteData implements RequestData
{
    public function __construct(
        #[Rule('required|string')]
        public readonly string $slug,
    ) {
    }
}
