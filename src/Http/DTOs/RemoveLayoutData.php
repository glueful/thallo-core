<?php

declare(strict_types=1);

namespace Thallo\Core\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/** DELETE /admin/layouts/{surface}/{target} body (type layouts spec §5.5): the session and the version. */
final class RemoveLayoutData implements RequestData
{
    public function __construct(
        #[Rule('required|string')]
        public readonly string $token = '',
        #[Rule('required|integer')]
        public readonly int $expected_lock_version = 0,
    ) {
    }
}
