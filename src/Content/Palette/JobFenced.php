<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Palette;

/**
 * A replace job that is no longer active (cancelled, or finished by another worker) reached a write:
 * nothing is written and the run stops (custom palette spec §4.4).
 */
final class JobFenced extends \RuntimeException
{
    public function __construct(public readonly string $jobId)
    {
        parent::__construct("palette job {$jobId} is no longer active");
    }
}
