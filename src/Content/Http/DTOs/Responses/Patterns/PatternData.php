<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs\Responses\Patterns;

use Glueful\Http\Contracts\ResponseData;

/**
 * Doc-only schema holder: one pattern of the section and page library. NEVER constructed at
 * runtime.
 */
final class PatternData implements ResponseData
{
    public function __construct(
        public readonly string $slug,
        /** `section` (one block) or `page` (several sections). */
        public readonly string $kind,
        public readonly string $label,
        /** The group it is listed under; `Pages` for a page, `Header` or `Footer` for a template. */
        public readonly string $category,
        public readonly string $description,
        /** @var list<array<string,mixed>> Block trees with no ids — the editor mints them. */
        public readonly array $blocks,
        /** Where it is offered: `page` (a page body) or `region` (the header or footer). */
        public readonly string $scope,
        /** `header` or `footer` for a region's pattern; null for a page body's. */
        public readonly ?string $region,
        /** True for a section this site saved from the stage; false for a shipped pattern. */
        public readonly bool $saved,
        /** A saved section's id, for renaming and deleting it; null for a shipped pattern. */
        public readonly ?string $id,
    ) {
    }
}
