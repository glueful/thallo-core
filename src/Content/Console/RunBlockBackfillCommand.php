<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Console;

use Thallo\Core\Content\Blocks\Migration\BlockBackfillRunner;
use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Re-drive a block-type schema migration's backfill (block-migrations spec §4).
 * A failed migration stays ACTIVE (write gate closed) until this converges it.
 */
#[AsCommand(
    name: 'thallo:blocks:migration:backfill',
    description: 'Run or resume the backfill for a block-type schema migration',
)]
final class RunBlockBackfillCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addArgument('migration', InputArgument::REQUIRED, 'The block migration uuid to run or resume');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var BlockBackfillRunner $runner */
        $runner = $this->getService(BlockBackfillRunner::class);
        $result = $runner->run((string) $input->getArgument('migration'));

        $this->line("done: {$result['done']}, failed: {$result['failed']}");
        if ($result['failed'] > 0) {
            $this->warning('Migration has failures — fix the reported items and re-run this command.');
            return self::FAILURE;
        }
        $this->success('Block migration backfill completed.');
        return self::SUCCESS;
    }
}
