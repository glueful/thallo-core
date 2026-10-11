<?php

declare(strict_types=1);

namespace Thallo\Core\Updates\Console;

use Glueful\Console\BaseCommand;
use Glueful\Database\Connection;
use Glueful\Support\Process\SymfonyProcessRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Thallo\Core\Updates\PgDumpConnection;

/**
 * The update's verified backup (spec §9; plan ruling 1): a custom-format dump that counts only when
 * pg_dump exited 0, the file is non-empty, and pg_restore --list can read it. Private permissions.
 */
#[AsCommand(
    name: 'thallo:update:backup',
    description: 'Take the verified database backup an update needs (used by the update runner)',
)]
final class UpdateBackupCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('to', null, InputOption::VALUE_REQUIRED, 'Folder to write the dump and its manifest into');
        $this->addOption('estimate', null, InputOption::VALUE_NONE, 'Only report the database size');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print one JSON line');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('estimate')) {
            /** @var Connection $db */
            $db = $this->getService(Connection::class);
            $bytes = (int) $db->getPDO()->query('SELECT pg_database_size(current_database())')->fetchColumn();
            $output->writeln((string) json_encode(['database_bytes' => $bytes]));
            return self::SUCCESS;
        }
        try {
            $conn = (new PgDumpConnection($this->getContext()))->resolve();
        } catch (\Throwable $e) {
            return $this->fail($output, $e->getMessage());
        }
        // Built here, as the extension installer builds it: the runner is not a container service.
        $runner = new SymfonyProcessRunner();
        $dir = (string) $input->getOption('to');
        if ($dir === '' || (!is_dir($dir) && !@mkdir($dir, 0700, true))) {
            return $this->fail($output, "Cannot create {$dir}");
        }
        chmod($dir, 0700);
        $file = $dir . '/database.dump';
        $env = getenv();
        $dump = $runner->run([
            'pg_dump',
            '--host=' . $conn['host'],
            '--port=' . $conn['port'],
            '--username=' . $conn['username'],
            '--no-password',
            '--format=custom',
            '--file=' . $file,
            $conn['database'],
        ], $dir, 3600, null, array_merge(is_array($env) ? $env : [], $conn['env']));
        if ($dump['exitCode'] !== 0) {
            return $this->fail($output, 'pg_dump failed: ' . trim($dump['output']));
        }
        clearstatcache();
        if (!is_file($file) || filesize($file) === 0) {
            return $this->fail($output, 'pg_dump wrote nothing');
        }
        chmod($file, 0600);
        $list = $runner->run(['pg_restore', '--list', $file], $dir, 600, null, null);
        if ($list['exitCode'] !== 0 || trim($list['output']) === '') {
            return $this->fail($output, 'pg_restore cannot read the dump: ' . trim($list['output']));
        }
        $manifest = [
            'database' => $conn['database'],
            'dumped_at' => gmdate('c'),
            'bytes' => filesize($file),
            'sha256' => hash_file('sha256', $file),
        ];
        file_put_contents($dir . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        chmod($dir . '/manifest.json', 0600);
        $output->writeln((string) json_encode($manifest, JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }

    private function fail(OutputInterface $output, string $error): int
    {
        $output->writeln((string) json_encode(['error' => $error], JSON_UNESCAPED_SLASHES));
        return self::FAILURE;
    }
}
