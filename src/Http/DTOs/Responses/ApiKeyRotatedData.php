<?php

declare(strict_types=1);

namespace Thallo\Core\Http\DTOs\Responses;

use Glueful\Http\Contracts\ResponseData;

/**
 * Doc-only shape of the rotate response
 * ({@see \Thallo\Core\Http\Controllers\ApiKeyAdminController::rotate()}). `plain` is the new one-time
 * plaintext; `old_expires_at` is when the rotated-from key stops working.
 */
final class ApiKeyRotatedData implements ResponseData
{
    public function __construct(
        public readonly ApiKeyData $api_key,
        public readonly string $plain,
        public readonly string $old_expires_at,
    ) {
    }
}
