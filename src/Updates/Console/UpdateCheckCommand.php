<?php

declare(strict_types=1);

namespace Thallo\Core\Updates\Console;

use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Thallo\Core\Updates\UpdateChecker;

#[AsCommand(
    name: 'thallo:update:check',
    description: 'Ask Packagist whether a newer glueful/thallo-core is published and show what this install runs',
)]
final class UpdateCheckCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Ask now, even if the daily check already ran');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var UpdateChecker $checker */
        $checker = $this->getService(UpdateChecker::class);
        $status = $checker->check((bool) $input->getOption('force'));

        $this->table(['Update notice', ''], [
            ['Installed', $status->development ? 'development checkout' : ($status->current ?? 'unknown')],
            ['Newest published', $status->latest ?? ($status->enabled ? 'none newer' : 'not checked')],
            ['Checks', $status->enabled ? 'on (UPDATE_CHECK_ENABLED)' : 'off (UPDATE_CHECK_ENABLED=false)'],
            ['Last check', $status->checkedAt ?? 'never'],
            ['Release notes', $status->notesUrl],
        ]);

        if ($status->available) {
            $this->success(sprintf('Thallo %s is available. Upgrade with:', $status->latest));
        } else {
            $this->info('This install is current. When an update is published, upgrade with:');
        }
        $this->line('  composer update && php glueful thallo:provision');

        return self::SUCCESS;
    }
}
