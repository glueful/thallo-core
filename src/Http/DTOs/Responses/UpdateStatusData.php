<?php

declare(strict_types=1);

namespace Thallo\Core\Http\DTOs\Responses;

use Glueful\Http\Contracts\ResponseData;

/** What the admin is told about updates (UpdateChecker::status()). */
final class UpdateStatusData implements ResponseData
{
    public function __construct(
        public readonly ?string $current,
        public readonly ?string $latest,
        public readonly bool $available,
        public readonly bool $development,
        public readonly bool $enabled,
        public readonly ?string $checkedAt,
        public readonly string $notesUrl,
    ) {
    }
}
