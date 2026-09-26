<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Request body for `POST /v1/admin/saved-sections`
 * ({@see \Thallo\Core\Content\Http\Controllers\SavedSectionController::store()}): the block to save
 * (validated as a page save would validate it), how the library names it, and where it belongs:
 * a page body (`scope` `page`, the default) or a region (`scope` `region` and its `region`).
 */
final class SaveSectionData implements RequestData
{
    /** @param array<string,mixed> $block */
    public function __construct(
        #[Rule('string')]
        public readonly string $name = '',
        #[Rule('array')]
        public readonly array $block = [],
        #[Rule('string')]
        public readonly ?string $category = null,
        #[Rule('string')]
        public readonly ?string $description = null,
        #[Rule('string')]
        public readonly ?string $scope = null,
        #[Rule('string')]
        public readonly ?string $region = null,
    ) {
    }
}
