<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Jobs;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Glueful\Queue\Job;
use Thallo\Core\Content\Palette\PaletteReplaceRunner;

/**
 * Runs one brand colour replacement (custom palette spec §4.4) in the workspace it was started in:
 * the runner is resolved inside that workspace, so every palette service is built for it. A
 * single-store site runs it directly.
 */
final class RunPaletteReplaceJob extends Job
{
    public function __construct(array $data = [], ?ApplicationContext $context = null)
    {
        parent::__construct($data, $context);
    }

    public function handle(): void
    {
        $context = $this->context;
        if (!$context instanceof ApplicationContext) {
            throw new \RuntimeException('RunPaletteReplaceJob requires an ApplicationContext to run.');
        }
        $data = $this->getData();
        $jobId = isset($data['job_id']) && is_string($data['job_id']) ? $data['job_id'] : '';
        if ($jobId === '') {
            throw new \InvalidArgumentException('RunPaletteReplaceJob: missing job_id.');
        }
        $workspace = isset($data['workspace']) && is_string($data['workspace']) ? $data['workspace'] : '';
        $container = $context->getContainer();
        $run = static fn () => $container->get(PaletteReplaceRunner::class)->run($jobId);
        if ($workspace !== '' && $container->has(TenantContextRunner::class)) {
            $runner = $container->get(TenantContextRunner::class);
            if ($runner instanceof TenantContextRunner) {
                $runner->runAsTenant($workspace, $run);
                return;
            }
        }
        $run();
    }
}
