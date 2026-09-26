<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Request body for `PATCH /v1/admin/saved-sections/{id}`: a new name, category or description.
 * The block itself is not edited here — save a new section from the stage instead.
 */
final class UpdateSavedSectionData implements RequestData
{
    public function __construct(
        #[Rule('string')]
        public readonly ?string $name = null,
        #[Rule('string')]
        public readonly ?string $category = null,
        #[Rule('string')]
        public readonly ?string $description = null,
    ) {
    }
}
