<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/** Request body for `POST /v1/admin/style-classes/{id}/jobs` (visual builder spec §4.5). */
final class StyleClassJobRequestData implements RequestData
{
    public function __construct(
        /** @var string `detach` (appearance-preserving) or `remove` (changes how pages look). */
        #[Rule('required|string|in:detach,remove')]
        public readonly string $kind,
    ) {
    }
}
