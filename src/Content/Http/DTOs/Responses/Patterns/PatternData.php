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
        /** The group it is listed under; `Pages` for a page. */
        public readonly string $category,
        public readonly string $description,
        /** @var list<array<string,mixed>> Block trees with no ids — the editor mints them. */
        public readonly array $blocks,
    ) {
    }
}
