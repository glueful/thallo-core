<?php

declare(strict_types=1);

namespace Thallo\Core\Setup\Console;

use Thallo\Core\Setup\Doctor\Check;
use Thallo\Core\Setup\Doctor\Doctor;
use Thallo\Core\Setup\PgsqlDatabaseConfigFactory;
use Glueful\Console\BaseCommand;
use Glueful\Installer\ConnectionTester;
use Glueful\Installer\EnvWriter;
use Glueful\Installer\InstallState;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function base_path;

#[AsCommand(
    name: 'thallo:doctor',
    description: 'Check that this host can run a Thallo instance (PHP, extensions, paths, database)',
)]
final class DoctorCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption(
            'strict',
            null,
            InputOption::VALUE_NONE,
            'Treat warnings (e.g. absent security keys) as failures',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $strict = (bool) $input->getOption('strict');
        $basePath = base_path($this->getContext());
        $doctor = new Doctor($basePath, PHP_VERSION, get_loaded_extensions());

        $checks = $doctor->preflight();

        // Reachability only when the DB is already configured in .env (best-effort).
        $state = new InstallState($basePath, $this->getContext());
        if ($state->isDatabaseConfigured()) {
            $config = (new PgsqlDatabaseConfigFactory())->fromEnv(new EnvWriter($basePath . '/.env'));
            $checks[] = $doctor->reachability($config, new ConnectionTester($this->getContext()));
        }

        $rows = [];
        $failed = false;
        foreach ($checks as $check) {
            $rows[] = [$this->badge($check->status), $check->name, $check->message];
            // A FAIL always fails; under --strict a WARN (e.g. missing keys) fails too.
            $failed = $failed
                || $check->status === Check::FAIL
                || ($strict && $check->status === Check::WARN);
        }
        $this->table(['', 'Check', 'Detail'], $rows);

        if ($failed) {
            $this->error('Some checks failed. Resolve them, then run `thallo setup`.');
            return self::FAILURE;
        }

        $this->success('Environment looks healthy.');
        return self::SUCCESS;
    }

    private function badge(string $status): string
    {
        return match ($status) {
            Check::OK => 'OK',
            Check::WARN => 'WARN',
            default => 'FAIL',
        };
    }
}
