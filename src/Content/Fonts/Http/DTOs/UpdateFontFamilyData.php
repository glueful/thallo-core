<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Fonts\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/** Request body for `PATCH /v1/admin/fonts/{id}`: a new name, a new fallback, or both. */
final class UpdateFontFamilyData implements RequestData
{
    public function __construct(
        #[Rule('string')]
        public readonly ?string $name = null,
        #[Rule('string')]
        public readonly ?string $fallback = null,
    ) {
    }
}
