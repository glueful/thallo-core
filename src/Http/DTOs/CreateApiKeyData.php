<?php

declare(strict_types=1);

namespace Thallo\Core\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Request body for `POST /v1/admin/api-keys`
 * ({@see \Thallo\Core\Http\Controllers\ApiKeyAdminController::store()}).
 *
 * Hydrated + format-validated by the router. The key is minted for the calling admin; `expires_at`
 * is parsed by the controller (any strtotime-understood date).
 *
 * @param list<string> $scopes
 * @param list<string> $allowed_ips
 */
final class CreateApiKeyData implements RequestData
{
    public function __construct(
        #[Rule('required|string|min:1')]
        public readonly string $name,
        #[Rule('array')]
        public readonly array $scopes = [],
        #[Rule('array')]
        public readonly array $allowed_ips = [],
        #[Rule('string')]
        public readonly ?string $expires_at = null,
        #[Rule('nullable|string')]
        public readonly ?string $tenant_uuid = null,
    ) {
    }
}
