<?php

declare(strict_types=1);

namespace Thallo\Core\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/** The activation continue and cancel bodies: the generation the caller is acting on. */
final class ActivationGenerationData implements RequestData
{
    public function __construct(
        #[Rule('required|integer')]
        public readonly int $generation = 0,
    ) {
    }
}
