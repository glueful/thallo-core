<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Console;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Thallo\Core\Content\Palette\PaletteHistoryPruner;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Prunes finished palette replace jobs older than 90 days and raises the history horizon (custom
 * palette plan Task 12). An editor open since before the horizon is told to reload rather than given
 * a partial history. Palette generations are per workspace, so with multi-store tenancy each
 * workspace is pruned in its own context, from its own jobs.
 */
#[AsCommand(
    name: 'thallo:palette:prune-history',
    description: 'Prune palette replace jobs finished more than 90 days ago',
)]
final class PrunePaletteHistoryCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->getService(SystemFlags::class)->tenancyEnabled()) {
            $removed = $this->getService(PaletteHistoryPruner::class)->prune();
            $output->writeln("Pruned {$removed} palette replace job(s).");
            return self::SUCCESS;
        }
        $removed = 0;
        $this->getService(TenantContextRunner::class)->forEachTenant(function () use (&$removed): void {
            // resolved inside the workspace: its own palette state and jobs
            $removed += $this->getService(PaletteHistoryPruner::class)->prune();
        });
        $output->writeln("Pruned {$removed} palette replace job(s) across workspaces.");
        return self::SUCCESS;
    }
}
