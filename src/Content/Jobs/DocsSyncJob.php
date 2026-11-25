<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Jobs;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Glueful\Queue\Job;
use Thallo\Core\Content\DocsSync\DocsSyncRunner;

/**
 * One delivery of a docs sync run (docs sync design §6.2): an execution attempt, in the workspace
 * the run was started in. The runner is resolved inside that workspace, as RunPaletteReplaceJob
 * resolves its runner, so every service it uses is built for it. The queue never retries it: a
 * delivery that dies is recovered by the sync itself (§6.4), within its own attempt budget.
 */
final class DocsSyncJob extends Job
{
    public function handle(): void
    {
        $context = $this->context;
        if (!$context instanceof ApplicationContext) {
            throw new \RuntimeException('DocsSyncJob requires an ApplicationContext.');
        }
        $data = $this->getData();
        $run = isset($data['run']) && is_string($data['run']) ? $data['run'] : '';
        if ($run === '') {
            throw new \InvalidArgumentException('DocsSyncJob: missing run.');
        }
        $workspace = isset($data['workspace']) && is_string($data['workspace']) ? $data['workspace'] : '';
        $container = $context->getContainer();
        $go = static fn () => $container->get(DocsSyncRunner::class)->run($run);
        if ($workspace !== '' && $container->has(TenantContextRunner::class)) {
            $runner = $container->get(TenantContextRunner::class);
            if ($runner instanceof TenantContextRunner) {
                $runner->runAsTenant($workspace, $go);
                return;
            }
        }
        $go();
    }

    /**
     * The data every push of this job carries. `maxAttempts` is what the queue drivers read when
     * they build the payload (a push never instantiates the job), so the queue delivers it once.
     *
     * @return array{run: string, workspace: ?string, maxAttempts: int}
     */
    public static function payload(string $run, ?string $workspace): array
    {
        return ['run' => $run, 'workspace' => $workspace, 'maxAttempts' => 1];
    }

    public function getMaxAttempts(): int
    {
        return 1;
    }
}
