<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Console;

use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Content\Blocks\BlockUsageScanner;
use Thallo\Core\Content\Regions\RegionDefinitions;
use Thallo\Core\Content\Regions\RegionRepository;
use Thallo\Core\Content\Starter\StarterProvenanceRepository;
use Glueful\Console\BaseCommand;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Thallo\Tenancy\System\SystemFlags;

use function app;
use function db;

/**
 * Pre-launch, one-off retirement of the deprecated `account-link` block (physically removed —
 * this is not a migration, there is nothing to migrate TO). For every tenant, in a single
 * transaction:
 *  - Fails closed: if {@see BlockUsageScanner} reports any entry draft/publication still
 *    referencing the block (its scan scope — drafts + pinned publications only, never regions),
 *    the tenant is left untouched and reported for operator review.
 *  - Otherwise strips any placed `account-link` instance from every region's top-level block
 *    list (order and every other block preserved), hard-deletes the `block_types` row, and
 *    deletes its starter-provenance row.
 * Idempotent: a tenant with none of the above is a no-op.
 */
#[AsCommand(
    name: 'thallo:account:retire-account-link',
    description: 'Retire the deprecated account-link block: remove placed instances, the block '
        . 'type, and its provenance (pre-launch cleanup).',
)]
final class RetireAccountLinkCommand extends BaseCommand
{
    private const SLUG = 'account-link';
    private const SOURCE_ID = 'thallo-account:account-link';

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $flags = $this->getService(SystemFlags::class);
        if (!$flags->tenancyEnabled()) {
            $status = $this->retireCurrent(null);
            $status === self::SUCCESS
                ? $this->success('account-link retired.')
                : $this->error('account-link retirement skipped — see above.');
            return $status;
        }

        $runner = $this->getService(TenantContextRunner::class);
        $anySkipped = false;
        $runner->forEachTenant(function (string $tenantUuid) use (&$anySkipped): void {
            if ($this->retireCurrent($tenantUuid) !== self::SUCCESS) {
                $anySkipped = true;
            }
        });

        if ($anySkipped) {
            $this->error('account-link retirement skipped for one or more tenants — see above.');
            return self::FAILURE;
        }
        $this->success('account-link retired for all tenants.');
        return self::SUCCESS;
    }

    /** Runs the whole retirement for the CURRENT tenant/context in one transaction. */
    private function retireCurrent(?string $tenantUuid): int
    {
        $context = $this->getContext();
        $label = $tenantUuid ?? 'current';
        $skippedForUsage = false;
        $removedInstances = 0;
        $typeExisted = $this->getService(BlockTypeRepository::class)->findBySlug(self::SLUG) !== null;

        try {
            db($context)->transaction(
                function () use ($context, $label, &$skippedForUsage, &$removedInstances): void {
                    $usage = app($context, BlockUsageScanner::class)->usage(self::SLUG);
                    $total = (int) ($usage['total'] ?? 0);
                    if ($total > 0) {
                        // Set BEFORE throwing: the catch below reads this flag to tell an
                        // expected fail-closed skip apart from a genuine bug.
                        $skippedForUsage = true;
                        throw new \RuntimeException(
                            "[{$label}] account-link is still referenced by {$total} entry "
                            . 'draft/publication(s) — operator review required'
                        );
                    }

                    $regions = $this->getService(RegionRepository::class);
                    foreach (RegionDefinitions::slugs() as $slug) {
                        $region = $regions->find($slug);
                        if ($region === null || $region['blocks'] === []) {
                            continue;
                        }
                        $blocks = $region['blocks'];
                        $filtered = array_values(array_filter(
                            $blocks,
                            static fn (array $block): bool => ($block['type'] ?? null) !== self::SLUG,
                        ));
                        if (count($filtered) === count($blocks)) {
                            continue;
                        }
                        $removedInstances += count($blocks) - count($filtered);
                        $regions->save($slug, $filtered, $region['settings'], null);

                        // Re-read to confirm the write took and no instance remains.
                        $reread = $regions->find($slug);
                        foreach (($reread['blocks'] ?? []) as $block) {
                            if (($block['type'] ?? null) === self::SLUG) {
                                throw new \RuntimeException(
                                    "[{$label}] account-link still present in region "
                                    . "'{$slug}' after save"
                                );
                            }
                        }
                    }

                    $this->getService(BlockTypeRepository::class)->deleteBySlug(self::SLUG);
                    $this->getService(StarterProvenanceRepository::class)
                        ->deleteBySource('block_type', self::SOURCE_ID);
                }
            );
        } catch (\RuntimeException $e) {
            if ($skippedForUsage) {
                $this->warning($e->getMessage() . ' — no changes made for this tenant.');
                return self::FAILURE;
            }
            throw $e;
        }

        $this->line(
            "[{$label}] removed {$removedInstances} instance(s); block type "
            . ($typeExisted ? 'deleted' : 'already absent') . '; provenance removed.'
        );
        return self::SUCCESS;
    }
}
