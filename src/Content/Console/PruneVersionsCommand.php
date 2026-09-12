<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Console;

use Thallo\Core\Content\Retention\InvalidRetentionPolicyException;
use Thallo\Core\Content\Retention\RetentionPolicy;
use Thallo\Core\Content\Retention\VersionPruner;
use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI-only deletion path for old, non-pinned entry_versions history. Operators
 * should export first if they need a recoverable archive.
 */
#[AsCommand(
    name: 'thallo:versions:prune',
    description: 'Delete out-of-policy, non-pinned entry_versions history',
)]
final class PruneVersionsCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->setHelp(
                "Deletes old, non-pinned version snapshots per (entry, locale) lineage.\n"
                . "The pinned version always survives. Deletion is permanent; export first.\n\n"
                . "  thallo:versions:prune --dry-run\n"
                . "  thallo:versions:prune --keep=10\n"
                . "  thallo:versions:prune --max-age-days=90\n"
                . 'With no configured or passed policy, pruning is a no-op.'
            )
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be deleted; delete nothing')
            ->addOption('keep', null, InputOption::VALUE_REQUIRED, 'Override: keep the N newest versions per lineage')
            ->addOption(
                'max-age-days',
                null,
                InputOption::VALUE_REQUIRED,
                'Override: delete versions older than D days',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $keep = $input->getOption('keep')
            ?? config($this->context, 'thallo.versions.retention.keep');
        $maxAge = $input->getOption('max-age-days')
            ?? config($this->context, 'thallo.versions.retention.max_age_days');

        try {
            $policy = RetentionPolicy::fromValues($keep, $maxAge);
        } catch (InvalidRetentionPolicyException $e) {
            $this->error('Invalid retention policy: ' . $e->getMessage());
            return self::FAILURE;
        }

        if (!$policy->isEnabled()) {
            $this->warning(
                'No retention policy configured (VERSION_KEEP / VERSION_MAX_AGE_DAYS) '
                . 'and no --keep/--max-age-days override; nothing to prune (unlimited history).',
            );
            return self::SUCCESS;
        }

        /** @var VersionPruner $pruner */
        $pruner = $this->getService(VersionPruner::class);
        $report = $pruner->prune($policy, $dryRun);

        if ($dryRun) {
            $this->info('DRY-RUN: no rows were deleted.');
        }

        foreach ($report->toArray() as $key => $value) {
            $this->line(sprintf('  %-18s %d', $key, $value));
        }

        $this->success(sprintf(
            '%s %d version(s) across %d lineage(s) (%d pinned-skipped).',
            $dryRun ? 'Would prune' : 'Pruned',
            $report->versionsDeleted,
            $report->lineagesScanned,
            $report->pinnedSkipped,
        ));

        return self::SUCCESS;
    }
}
