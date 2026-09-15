<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Request body for `PATCH /v1/admin/style-classes/{id}` (visual builder spec §4.3): `version`
 * is the version the client loaded — a stale one is 409 `STYLE_CLASS_VERSION_CONFLICT`. Only
 * the keys present change.
 */
final class UpdateStyleClassData implements RequestData
{
    /** @param array<string,mixed>|null $style */
    public function __construct(
        #[Rule('required|integer')]
        public readonly int $version,
        #[Rule('string')]
        public readonly ?string $name = null,
        #[Rule('string')]
        public readonly ?string $description = null,
        #[Rule('array')]
        public readonly ?array $style = null,
    ) {
    }
}
