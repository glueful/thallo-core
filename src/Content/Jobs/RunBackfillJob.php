<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Jobs;

use Thallo\Core\Content\Backfill\BackfillRunner;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Queue\Job;

final class RunBackfillJob extends Job
{
    public function handle(): void
    {
        $context = $this->context;
        if (!$context instanceof ApplicationContext) {
            throw new \RuntimeException('RunBackfillJob requires an ApplicationContext to run.');
        }

        $data = $this->getData();
        $migrationUuid = isset($data['migration_uuid']) && is_string($data['migration_uuid'])
            ? $data['migration_uuid']
            : '';
        if ($migrationUuid === '') {
            throw new \InvalidArgumentException('RunBackfillJob: missing migration_uuid.');
        }

        /** @var BackfillRunner $runner */
        $runner = $context->getContainer()->get(BackfillRunner::class);
        $runner->run($migrationUuid);
    }
}
