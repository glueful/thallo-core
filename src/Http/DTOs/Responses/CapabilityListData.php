<?php

declare(strict_types=1);

namespace Thallo\Core\Http\DTOs\Responses;

use Glueful\Http\Contracts\ResponseData;

/**
 * Doc-only envelope for the capabilities response
 * ({@see \Thallo\Core\Http\Controllers\CapabilityAdminController::index()}).
 */
final class CapabilityListData implements ResponseData
{
    /** @param list<CapabilityData> $capabilities */
    public function __construct(
        public readonly array $capabilities,
    ) {
    }
}
