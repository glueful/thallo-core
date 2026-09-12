<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Console;

use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Content\Blocks\StarterBlockTypes;
use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Additively syncs evolved starter block-type schemas onto existing rows.
 *
 * The seeder (thallo:blocks:seed) is create-only by design — it never touches an
 * existing row, so new fields added to a StarterBlockTypes definition never reach
 * already-seeded installs. This closes that gap the SAFE way: for each starter it
 * PRESERVES the existing field order and APPENDS (via array_merge) any starter field
 * whose `name` is absent from the DB row's schema. Operator-added fields and the
 * row's label/icon/description/category are left untouched, and field REMOVAL is
 * never performed here — that is the migration flow's job — so this is non-destructive
 * and mirrors updateSchema's additive-only guard. `--dry-run` reports the same
 * "synced …" lines without writing, so it doubles as a safe pre-upgrade preview.
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
        /** @var BlockTypeRepository $repo */
        $repo = $this->getService(BlockTypeRepository::class);
        $synced = 0;
        $unchanged = 0;
        $missing = 0;
        foreach (StarterBlockTypes::definitions() as $definition) {
            $row = $repo->findBySlug($definition['slug']);
            if ($row === null) {
                $this->line("missing {$definition['slug']} (run thallo:blocks:seed)");
                $missing++;
                continue;
            }
            $existing = array_column($row['schema'], 'name');
            $toAdd = array_values(array_filter(
                $definition['schema'],
                static fn (array $f): bool => !in_array($f['name'], $existing, true),
            ));
            // Presentation-metadata attach: a same-name field the starter now
            // labels (enum_labels) gets the labels ADDED when the row has none.
            // Additive-only, like everything here: an operator's own labels are
            // never overwritten, and no other key of an existing field changes.
            $patched = $row['schema'];
            $labelled = [];
            foreach ($definition['schema'] as $starterField) {
                if (!isset($starterField['enum_labels'])) {
                    continue;
                }
                foreach ($patched as $i => $rowField) {
                    if (($rowField['name'] ?? null) === $starterField['name'] && !isset($rowField['enum_labels'])) {
                        $patched[$i]['enum_labels'] = $starterField['enum_labels'];
                        $labelled[] = (string) $starterField['name'];
                    }
                }
            }
            if ($toAdd === [] && $labelled === []) {
                $unchanged++;
                continue;
            }
            if (!$dryRun) {
                $repo->updateSchema(
                    (string) $row['uuid'],
                    array_merge($patched, $toAdd),
                    (string) $row['label'],
                    $row['icon'] !== null ? (string) $row['icon'] : null,
                    $row['description'] !== null ? (string) $row['description'] : null,
                    $row['category'] !== null ? (string) $row['category'] : null,
                );
            }
            $parts = [];
            if ($toAdd !== []) {
                $parts[] = '+' . count($toAdd) . ': ' . implode(', ', array_column($toAdd, 'name'));
            }
            if ($labelled !== []) {
                $parts[] = 'labels: ' . implode(', ', $labelled);
            }
            $this->line("synced {$definition['slug']} (" . implode('; ', $parts) . ')');
            $synced++;
        }
        $summary = "Synced {$synced}, unchanged {$unchanged}, missing {$missing}.";
        $this->success($dryRun ? "[dry-run] {$summary} No changes written." : $summary);
        return self::SUCCESS;
    }
}
