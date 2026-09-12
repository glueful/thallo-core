<?php

declare(strict_types=1);

namespace Thallo\Core\Http\DTOs\Responses;

use Glueful\Http\Contracts\ResponseData;

final class UpdateStatusResultData implements ResponseData
{
    public function __construct(
        public readonly UpdateStatusData $update,
    ) {
    }
}
