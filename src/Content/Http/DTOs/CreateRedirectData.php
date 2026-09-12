<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

final class CreateRedirectData implements RequestData
{
    /**
     * @param array<string,mixed> $target
     */
    public function __construct(
        #[Rule('required|string')]
        public readonly string $locale,
        #[Rule('required|string')]
        public readonly string $source_slug,
        #[Rule('required|array')]
        public readonly array $target,
        public readonly int $status = 301,
    ) {
    }
}
