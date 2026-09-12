<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Console;

use Thallo\Core\Content\Scheduling\ScheduleRunner;
use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'thallo:schedules:run',
    description: 'Fire due scheduled publish/unpublish actions through the normal publish path',
)]
final class RunDueSchedulesCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max schedules to fire this run', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var ScheduleRunner $runner */
        $runner = $this->getService(ScheduleRunner::class);
        $fired = $runner->run(max(1, (int) $input->getOption('limit')));

        $this->success(sprintf('Fired %d scheduled action(s).', $fired));

        return self::SUCCESS;
    }
}
