<?php

declare(strict_types=1);

namespace Thallo\Core\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

final class UpdateApiKeyTenantData implements RequestData
{
    public function __construct(
        #[Rule('nullable|string')]
        public readonly ?string $tenant_uuid = null,
    ) {
    }
}
