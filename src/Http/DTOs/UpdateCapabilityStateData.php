<?php

declare(strict_types=1);

namespace Thallo\Core\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/** PUT /v1/admin/capabilities/{id} — the operator's requested-state flip. */
final class UpdateCapabilityStateData implements RequestData
{
    public function __construct(
        #[Rule('required|boolean')]
        public readonly bool $enabled,
    ) {
    }
}
