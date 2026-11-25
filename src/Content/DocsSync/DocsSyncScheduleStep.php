<?php

declare(strict_types=1);

namespace Thallo\Core\Content\DocsSync;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Scheduler\JobScheduler;
use Thallo\Core\Content\Jobs\DocsSyncPruneJob;
use Thallo\Core\Content\Jobs\DocsSyncRecoverJob;

/**
 * Provision's step registering the docs sync's recurring tasks in the scheduler's table
 * (docs sync plan, ruling 1). The scheduler loads that table as well as the site's own
 * config/schedule.php, and registering is idempotent: a row is updated in place, keeping its uuid,
 * history and whether an operator turned it off. So it reaches every install on its next provision.
 */
final class DocsSyncScheduleStep
{
    public const TASKS = [
        'docs_sync_recover' => ['*/5 * * * *', DocsSyncRecoverJob::class],
        'docs_sync_prune' => ['17 3 * * *', DocsSyncPruneJob::class],
    ];

    public const SKIPPED = 'Docs sync recovery runs only when its card is opened: the scheduler\'s '
        . 'database store is off (SCHEDULE_DATABASE_STORE).';

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly ?bool $schedulerStore = null,
    ) {
    }

    /** @return array{registered: list<string>, skipped: ?string} */
    public function run(): array
    {
        $store = $this->schedulerStore ?? (bool) config($this->context, 'capabilities.scheduler', true);
        if (!$store || !Connection::fromContext($this->context)->getSchemaBuilder()->hasTable('scheduled_jobs')) {
            return ['registered' => [], 'skipped' => self::SKIPPED];
        }
        $scheduler = new JobScheduler(null, $this->context);
        foreach (self::TASKS as $name => [$schedule, $handler]) {
            $scheduler->registerInDatabase($name, $schedule, $handler);
        }
        return ['registered' => array_keys(self::TASKS), 'skipped' => null];
    }
}
