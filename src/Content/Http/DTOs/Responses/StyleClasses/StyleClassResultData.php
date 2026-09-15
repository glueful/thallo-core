<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs\Responses\StyleClasses;

use Glueful\Http\Contracts\ResponseData;

/** Doc-only schema holder: the envelope `data` wrapping one style class. NEVER constructed at runtime. */
final class StyleClassResultData implements ResponseData
{
    public function __construct(public readonly StyleClassItemData $style_class)
    {
    }
}
