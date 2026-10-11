<?php

declare(strict_types=1);

namespace Thallo\Core\Http\DTOs\Responses;

use Glueful\Http\Contracts\ResponseData;

/** A started update: its id, and the one-time token the /_update page uses. */
final class UpdateStartData implements ResponseData
{
    public function __construct(
        public readonly string $id,
        public readonly string $token,
    ) {
    }
}
