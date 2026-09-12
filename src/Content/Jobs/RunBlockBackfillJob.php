<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Jobs;

use Thallo\Core\Content\Blocks\Migration\BlockBackfillRunner;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Queue\Job;

final class RunBlockBackfillJob extends Job
{
    public function handle(): void
    {
        $context = $this->context;
        if (!$context instanceof ApplicationContext) {
            throw new \RuntimeException('RunBlockBackfillJob requires an ApplicationContext to run.');
        }

        $data = $this->getData();
        $migrationUuid = isset($data['migration_uuid']) && is_string($data['migration_uuid'])
            ? $data['migration_uuid']
            : '';
        if ($migrationUuid === '') {
            throw new \InvalidArgumentException('RunBlockBackfillJob: missing migration_uuid.');
        }

        /** @var BlockBackfillRunner $runner */
        $runner = $context->getContainer()->get(BlockBackfillRunner::class);
        $runner->run($migrationUuid);
    }
}
