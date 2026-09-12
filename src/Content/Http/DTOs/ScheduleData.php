<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Request body for POST /v1/admin/entries/{uuid}/schedules/{locale}.
 */
final class ScheduleData implements RequestData
{
    public function __construct(
        #[Rule('required|string')]
        public readonly string $action = '',
        #[Rule('required|string')]
        public readonly string $run_at = '',
    ) {
    }
}
