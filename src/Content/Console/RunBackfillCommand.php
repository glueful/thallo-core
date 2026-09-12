<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Console;

use Thallo\Core\Content\Backfill\BackfillRunner;
use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'thallo:schema:backfill',
    description: 'Run or resume the backfill for a destructive schema migration',
)]
final class RunBackfillCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addArgument('migration', InputArgument::REQUIRED, 'The migration uuid to run or resume');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var BackfillRunner $runner */
        $runner = $this->getService(BackfillRunner::class);
        $result = $runner->run((string) $input->getArgument('migration'));

        $this->success(sprintf(
            'Backfill done: %d materialized, %d failed.',
            $result['done'],
            $result['failed'],
        ));

        return self::SUCCESS;
    }
}
