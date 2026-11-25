<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Jobs;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Queue\Job;
use Thallo\Core\Content\DocsSync\DocsSyncService;
use Thallo\Core\Content\DocsSync\DocsSyncWorkspaces;

/**
 * The recurring docs sync recover task (docs sync design §6.4 / §4), registered in the scheduler's
 * table by provision (DocsSyncScheduleStep), in every workspace.
 */
final class DocsSyncRecoverJob extends Job
{
    private ?DocsSyncService $service = null;

    /** Tests only: the service to use instead of the container's. */
    public function useService(DocsSyncService $service): void
    {
        $this->service = $service;
    }

    public function handle(): void
    {
        $context = $this->context;
        if (!$context instanceof ApplicationContext) {
            throw new \RuntimeException('DocsSyncRecoverJob requires an ApplicationContext.');
        }
        $container = $context->getContainer();
        $container->get(DocsSyncWorkspaces::class)->each('recover', function () use ($container): void {
            ($this->service ?? $container->get(DocsSyncService::class))->recover();
        });
    }
}
