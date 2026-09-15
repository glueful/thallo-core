<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Jobs;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Queue\Job;
use Thallo\Core\Content\Style\Classes\StyleClassJobRunner;

/** Runs one style class job (visual builder spec §4.5) with the application context it was handed. */
final class RunStyleClassJob extends Job
{
    public function __construct(array $data = [], ?ApplicationContext $context = null)
    {
        parent::__construct($data, $context);
    }

    public function handle(): void
    {
        $context = $this->context;
        if (!$context instanceof ApplicationContext) {
            throw new \RuntimeException('RunStyleClassJob requires an ApplicationContext to run.');
        }
        $data = $this->getData();
        $jobId = isset($data['job_id']) && is_string($data['job_id']) ? $data['job_id'] : '';
        if ($jobId === '') {
            throw new \InvalidArgumentException('RunStyleClassJob: missing job_id.');
        }
        $context->getContainer()->get(StyleClassJobRunner::class)->run($jobId);
    }
}
