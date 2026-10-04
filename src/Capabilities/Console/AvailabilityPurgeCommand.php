<?php

declare(strict_types=1);

namespace Thallo\Core\Capabilities\Console;

use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Thallo\Core\Capabilities\AvailabilityPurge;

/**
 * The scheduled side of {@see AvailabilityPurge}: finishes a due edge-purge retry, then reconciles
 * the marker — so a change made only in configuration purges even when no page is requested.
 */
#[AsCommand(
    name: 'thallo:availability:purge',
    description: 'Purge cached pages after the features in use changed, and finish the delayed CDN purge',
)]
final class AvailabilityPurgeCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $purge = $this->getService(AvailabilityPurge::class);
        $purge->completeDue(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $purge->reconcile();
        return self::SUCCESS;
    }
}
