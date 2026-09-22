<?php

declare(strict_types=1);

namespace Thallo\Core\Setup\Console;

use Thallo\Core\Setup\Doctor\Check;
use Thallo\Core\Setup\Doctor\Doctor;
use Thallo\Core\Settings\GeneralSettings;
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

        // Reachability only when the DB is already configured in .env (best-effort).
        $reachability = null;
        $state = new InstallState($basePath, $this->getContext());
        if ($state->isDatabaseConfigured()) {
            $config = (new PgsqlDatabaseConfigFactory())->fromEnv(new EnvWriter($basePath . '/.env'));
            $reachability = $doctor->reachability($config, new ConnectionTester($this->getContext()));
        }

        // The Appearance page's theme wins over RENDER_THEME at runtime, so check that one when
        // the database can say what it is.
        if ($reachability?->status === Check::OK) {
            $doctor = new Doctor($basePath, PHP_VERSION, get_loaded_extensions(), null, $this->storedTheme());
        }

        $checks = $doctor->preflight();
        if ($reachability !== null) {
            $checks[] = $reachability;
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
            $this->error('Some checks failed. Resolve them, then run php glueful thallo:provision.');
            return self::FAILURE;
        }

        $this->success('Environment looks healthy.');
        return self::SUCCESS;
    }

    /** The raw stored theme row; null before migrations run or when settings cannot be read. */
    private function storedTheme(): ?string
    {
        try {
            return $this->getService(GeneralSettings::class)->themeOverride();
        } catch (\Throwable) {
            return null;
        }
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
