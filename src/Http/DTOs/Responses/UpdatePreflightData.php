<?php

declare(strict_types=1);

namespace Thallo\Core\Http\DTOs\Responses;

use Glueful\Http\Contracts\ResponseData;

/** Whether the admin's Update button may run, and why not (UpdatePreflight::check()). */
final class UpdatePreflightData implements ResponseData
{
    /** @param list<string> $reasons */
    public function __construct(
        public readonly bool $ready,
        public readonly ?string $current,
        public readonly ?string $target,
        public readonly array $reasons,
    ) {
    }
}
