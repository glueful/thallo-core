<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Jobs;

use Glueful\Queue\Job;

/**
 * One delivery of a docs sync run (docs sync design §6.2): an execution attempt. The runner is
 * added in the next step of the build; the queue's own retries are not used (getMaxAttempts()).
 */
final class DocsSyncJob extends Job
{
    public function handle(): void
    {
    }

    public function getMaxAttempts(): int
    {
        return 1;
    }
}
