<?php

declare(strict_types=1);

namespace Thallo\Core\Http\DTOs\Responses;

use Glueful\Http\Contracts\ResponseData;

/**
 * Doc-only shape of the API-key list envelope
 * ({@see \Thallo\Core\Http\Controllers\ApiKeyAdminController::index()}).
 */
final class ApiKeyListData implements ResponseData
{
    /** @param list<ApiKeyData> $api_keys */
    public function __construct(
        public readonly array $api_keys,
        public readonly int $total,
        public readonly int $current_page,
        public readonly int $per_page,
    ) {
    }
}
