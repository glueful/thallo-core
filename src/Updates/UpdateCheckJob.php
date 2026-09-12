<?php

declare(strict_types=1);

namespace Thallo\Core\Updates;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Queue\Job;

/**
 * The daily update check (config/schedule.php, `update_check`). A thin entry point: the checker
 * decides whether to ask Packagist and stays silent on failure, and nothing here throws out of
 * the scheduler.
 */
final class UpdateCheckJob extends Job
{
    public function handle(): void
    {
        if (!$this->context instanceof ApplicationContext) {
            throw new \RuntimeException('UpdateCheckJob requires an ApplicationContext.');
        }

        try {
            app($this->context, UpdateChecker::class)->check();
        } catch (\Throwable $e) {
            error_log('[Thallo] Update check did not complete: ' . $e->getMessage());
        }
    }
}
