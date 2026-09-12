<?php

declare(strict_types=1);

namespace Thallo\Core\Http\DTOs\Responses;

use Glueful\Http\Contracts\ResponseData;

/**
 * Doc-only shape of the create response
 * ({@see \Thallo\Core\Http\Controllers\ApiKeyAdminController::store()}). `plain` is the one-time plaintext
 * key — returned only here, never stored.
 */
final class ApiKeyCreatedData implements ResponseData
{
    public function __construct(
        public readonly ApiKeyData $api_key,
        public readonly string $plain,
    ) {
    }
}
