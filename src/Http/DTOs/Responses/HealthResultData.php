<?php

declare(strict_types=1);

namespace Thallo\Core\Http\DTOs\Responses;

use Glueful\Http\Contracts\ResponseData;

/**
 * Doc-only envelope for the health response
 * ({@see \Thallo\Core\Http\Controllers\HealthAdminController::show()}).
 */
final class HealthResultData implements ResponseData
{
    public function __construct(
        public readonly HealthData $health,
    ) {
    }
}
