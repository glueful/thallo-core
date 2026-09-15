<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Console;

use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Thallo\Core\Content\Style\Classes\StyleClassJobRunner;

/** The CLI counterpart of the queued style class job (visual builder spec §4.5). */
#[AsCommand(
    name: 'thallo:style-classes:run-job',
    description: 'Run or resume a detach-everywhere or remove-everywhere style class job',
)]
final class RunStyleClassJobCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addArgument('job', InputArgument::REQUIRED, 'The style class job id to run or resume');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var StyleClassJobRunner $runner */
        $runner = $this->getService(StyleClassJobRunner::class);
        $result = $runner->run((string) $input->getArgument('job'));
        $this->line(sprintf(
            'status: %s, passes: %d, done: %d, failed: %d',
            $result['status'],
            $result['passes'],
            $result['done'],
            $result['failed'],
        ));
        if ($result['status'] !== 'completed') {
            $this->warning('The job did not complete — fix the reported documents and queue it again.');
            return self::FAILURE;
        }
        $this->success('Style class job completed.');
        return self::SUCCESS;
    }
}
