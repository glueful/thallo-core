<?php

declare(strict_types=1);

namespace Thallo\Core\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * POST /admin/layouts/preview/apply body: the session token, the layout as the stage should show it
 * (`{blocks, settings}`) and the compare-and-set pair (type layouts spec §5.3). LayoutValidator owns
 * the rules, so its errors carry `blocks.3.data.field` / `settings.width` dot paths.
 */
final class ApplyLayoutData implements RequestData
{
    public function __construct(
        #[Rule('required|string')]
        public readonly string $token = '',
        /** @var array{blocks?: list<array<string,mixed>>, settings?: array<string,mixed>} */
        #[Rule('array')]
        public readonly array $layout = [],
        #[Rule('nullable|string')]
        public readonly ?string $epoch = null,
        #[Rule('nullable|integer')]
        public readonly ?int $base_revision = null,
        /** @var list<array<string,mixed>> */
        #[Rule('nullable|array')]
        public readonly ?array $operations = null,
    ) {
    }
}
