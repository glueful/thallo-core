<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Fonts\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/** Request body for `POST /v1/admin/fonts/{id}/faces`: another `.woff2` file for the family. */
final class AddFontFaceData implements RequestData
{
    public function __construct(
        #[Rule('string')]
        public readonly string $blob_uuid = '',
    ) {
    }
}
