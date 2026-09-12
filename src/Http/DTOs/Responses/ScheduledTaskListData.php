<?php

declare(strict_types=1);

namespace Thallo\Core\Http\DTOs\Responses;

use Glueful\Http\Contracts\ResponseData;

/** Doc-only list envelope ({@see \Thallo\Core\Http\Controllers\ScheduledTasksController::index()}). */
final class ScheduledTaskListData implements ResponseData
{
    /** @param list<ScheduledTaskData> $tasks */
    public function __construct(
        public readonly array $tasks,
    ) {
    }
}
