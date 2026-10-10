<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Console;

use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Thallo\Core\Content\Palette\PaletteHistoryPruner;

/**
 * Prunes finished palette replace jobs older than 90 days and raises the history horizon (custom
 * palette plan Task 12). An editor open since before the horizon is told to reload rather than given
 * a partial history.
 */
#[AsCommand(
    name: 'thallo:palette:prune-history',
    description: 'Prune palette replace jobs finished more than 90 days ago',
)]
final class PrunePaletteHistoryCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $removed = $this->getService(PaletteHistoryPruner::class)->prune();
        $output->writeln("Pruned {$removed} palette replace job(s).");
        return self::SUCCESS;
    }
}
