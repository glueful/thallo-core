<?php

declare(strict_types=1);

namespace Thallo\Core\Http\DTOs\Responses;

use Glueful\Http\Contracts\ResponseData;

/** Doc-only shape of one enabled capability in the capabilities response. */
final class CapabilityData implements ResponseData
{
    /** @param list<string> $requires */
    public function __construct(
        public readonly string $id,
        public readonly ?string $label,
        public readonly ?string $description,
        public readonly array $requires,
    ) {
    }
}
