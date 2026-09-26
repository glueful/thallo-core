<?php

declare(strict_types=1);

namespace Thallo\Core\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * PUT /admin/layouts/{surface}/{target} body (type layouts spec §5.5): the editing session's token,
 * the layout, the version the editor loaded, and the working copy pair the save was made from.
 */
final class SaveLayoutData implements RequestData
{
    public function __construct(
        #[Rule('required|string')]
        public readonly string $token = '',
        /** @var array{blocks?: list<array<string,mixed>>, settings?: array<string,mixed>} */
        #[Rule('array')]
        public readonly array $layout = [],
        #[Rule('required|integer')]
        public readonly int $expected_lock_version = 0,
        /** @var array{epoch?: string, revision?: int}|null */
        #[Rule('nullable|array')]
        public readonly ?array $preview_revision = null,
    ) {
    }
}
