<?php

declare(strict_types=1);

namespace Thallo\Core\Signup;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Queue\Job;

final class SignupIntentSweepJob extends Job
{
    public function handle(): void
    {
        if (!$this->context instanceof ApplicationContext) {
            throw new \RuntimeException('SignupIntentSweepJob requires an ApplicationContext.');
        }
        $repository = app($this->context, SignupIntentRepository::class);
        $days = max(1, (int) config($this->context, 'signup.consumed_retention_days', 7));
        $repository->sweepExpired();
        $repository->sweepConsumedBefore(new \DateTimeImmutable("-{$days} days"));
        $repository->pruneRateCountersBefore(new \DateTimeImmutable('-2 days'));
    }
}
