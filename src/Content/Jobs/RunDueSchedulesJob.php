<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Jobs;

use Thallo\Core\Content\Scheduling\ScheduleRunner;
use Glueful\Queue\Job;

/**
 * Cron entry point for scheduled publish/unpublish.
 *
 * The scheduler resolves handler_class through the queue JobInterface path, while manual
 * operators use thallo:schedules:run. Both delegate to the shared ScheduleRunner.
 */
final class RunDueSchedulesJob extends Job
{
    public function handle(): void
    {
        if ($this->context === null) {
            return;
        }

        app($this->context, ScheduleRunner::class)->run();
    }
}
