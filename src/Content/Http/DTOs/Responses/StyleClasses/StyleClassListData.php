<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs\Responses\StyleClasses;

use Glueful\Http\Contracts\ResponseData;
use Glueful\Validation\Attributes\ArrayOf;

/**
 * Doc-only schema holder: `GET /v1/admin/style-classes` — the site's classes from one snapshot
 * and the generation that names it (visual builder spec §4.3). NEVER constructed at runtime.
 */
final class StyleClassListData implements ResponseData
{
    /** @param list<StyleClassItemData> $style_classes */
    public function __construct(
        /** The site style generation the listed classes belong to. */
        public readonly int $generation,
        #[ArrayOf(StyleClassItemData::class)]
        public readonly array $style_classes,
    ) {
    }
}
