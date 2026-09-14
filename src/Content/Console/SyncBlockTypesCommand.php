<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Console;

use Thallo\Core\Content\Blocks\StarterBlockTypeSync;
use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Syncs evolved starter block-type definitions onto existing rows ({@see StarterBlockTypeSync}):
 * additive for fields, the starter's own for the style declaration. `thallo:provision` runs the
 * same sync once installed; this command is the manual and multi-workspace path, and
 * `--dry-run` reports the same "synced …" lines without writing.
 */
#[AsCommand(
    name: 'thallo:blocks:sync',
    description: 'Additively add new starter block-type fields to existing rows (never removes).',
    aliases: ['blocks:sync'],
)]
final class SyncBlockTypesCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change without writing.');
        $this->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Tenant uuid.');
        $this->addOption('all', null, InputOption::VALUE_NONE, 'Sync all active tenants.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $flags = $this->getService(SystemFlags::class);
        if (!$flags->tenancyEnabled()) {
            return $this->syncCurrent((bool) $input->getOption('dry-run'));
        }
        $tenant = $input->getOption('tenant');
        $all = (bool) $input->getOption('all');
        if ($all === (is_string($tenant) && trim($tenant) !== '')) {
            $this->error('When tenancy is enabled, supply exactly one --tenant or --all.');
            return self::FAILURE;
        }
        $dryRun = (bool) $input->getOption('dry-run');
        $runner = $this->getService(TenantContextRunner::class);
        if ($all) {
            $runner->forEachTenant(fn() => $this->syncCurrent($dryRun));
        } else {
            $runner->runAsTenant(trim((string) $tenant), fn() => $this->syncCurrent($dryRun));
        }
        return self::SUCCESS;
    }

    private function syncCurrent(bool $dryRun): int
    {
        $result = $this->getService(StarterBlockTypeSync::class)->sync($dryRun);
        foreach ($result['missing'] as $slug) {
            $this->line("missing {$slug} (run thallo:blocks:seed)");
        }
        foreach ($result['synced'] as $entry) {
            $this->line("synced {$entry['slug']} (" . implode('; ', $entry['parts']) . ')');
        }
        $summary = sprintf(
            'Synced %d, unchanged %d, missing %d.',
            count($result['synced']),
            $result['unchanged'],
            count($result['missing']),
        );
        $this->success($dryRun ? "[dry-run] {$summary} No changes written." : $summary);
        return self::SUCCESS;
    }
}
