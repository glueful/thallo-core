<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

final class CopyLocaleData implements RequestData
{
    public function __construct(
        #[Rule('string')]
        public readonly ?string $source_locale = null,
        #[Rule('boolean')]
        public readonly bool $overwrite = false,
    ) {
    }
}
