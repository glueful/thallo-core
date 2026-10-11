<?php

declare(strict_types=1);

namespace Thallo\Core\Updates\Console;

use Composer\InstalledVersions;
use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** The update runner's command-line check (spec §8 step 8): which glueful/thallo-core Composer installed. */
#[AsCommand(
    name: 'thallo:update:verify',
    description: 'Report the installed glueful/thallo-core version (used by the update runner)',
)]
final class UpdateVerifyCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print one JSON line');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $version = InstalledVersions::isInstalled('glueful/thallo-core')
            ? InstalledVersions::getPrettyVersion('glueful/thallo-core')
            : null;
        $output->writeln((string) json_encode(['version' => $version, 'php' => PHP_VERSION]));
        return self::SUCCESS;
    }
}
