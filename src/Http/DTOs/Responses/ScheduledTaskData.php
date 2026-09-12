<?php

declare(strict_types=1);

namespace Thallo\Core\Http\DTOs\Responses;

use Glueful\Http\Contracts\ResponseData;

/**
 * Doc-only shape of one scheduled task ({@see \Thallo\Core\Http\Controllers\ScheduledTasksController}).
 * Sourced from config/schedule.php; `enabled` is the configured state (read-only) and `next_run`
 * is computed from the cron expression.
 */
final class ScheduledTaskData implements ResponseData
{
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly string $schedule,
        public readonly ?string $next_run,
        public readonly bool $enabled,
        public readonly string $handler_class,
        public readonly string $queue,
    ) {
    }
}
