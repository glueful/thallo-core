<?php

declare(strict_types=1);

namespace Thallo\Core\Http\DTOs\Responses;

use Glueful\Http\Contracts\ResponseData;

/**
 * Doc-only shape of a single-key response ({@see \Thallo\Core\Http\Controllers\ApiKeyAdminController::show()}).
 */
final class ApiKeyResultData implements ResponseData
{
    public function __construct(
        public readonly ApiKeyData $api_key,
    ) {
    }
}
