<?php

declare(strict_types=1);

namespace Thallo\Core\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/** POST /admin/layouts/preview/session body: the layout to edit and, optionally, the sample to show. */
final class LayoutSessionData implements RequestData
{
    public function __construct(
        #[Rule('required|string')]
        public readonly string $surface = '',
        #[Rule('required|string')]
        public readonly string $target = '',
        /** A published item's id; absent or unavailable means the newest, else a placeholder. */
        #[Rule('nullable|string')]
        public readonly ?string $sample = null,
    ) {
    }
}
