<?php

declare(strict_types=1);

namespace Thallo\Core\Http\DTOs\Responses;

use Glueful\Http\Contracts\ResponseData;

/**
 * Doc-only result of queuing a task to run now
 * ({@see \Thallo\Core\Http\Controllers\ScheduledTasksController::run()}). `job_id` is the queued job id.
 */
final class RunTaskResultData implements ResponseData
{
    public function __construct(
        public readonly string $name,
        public readonly string $job_id,
    ) {
    }
}
