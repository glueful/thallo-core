<?php

declare(strict_types=1);

namespace Thallo\Core\Updater;

/**
 * The update runner (spec §3, §8, §10). Run by cron as the code owner: `php bin/thallo-updater tick`.
 * Self-contained once started; everything that needs the application runs as a child. Every write
 * names the operation id and the owner this runner was given (OperationStore fencing).
 */
final class Runner
{
    public const STEPS = [
        'preflight',
        'stage',
        'close',
        'barrier',
        'backup',
        'swap',
        'provision',
        'verify_cli',
        'verify_web',
        'reopen',
    ];

    /** @var resource|null */
    private $runLock = null;
    /** @var resource|null */
    private $barrierLock = null;
    private ?Journal $journal = null;
    private ?string $id = null;
    private ?string $owner = null;

    public function __construct(
        private readonly Paths $paths,
        private readonly OperationStore $ops,
        private readonly Barrier $barrier,
        private readonly Host $host,
        private readonly array $settings,
        private readonly \Closure $identity,
        private readonly ?\Closure $onKillPoint = null,
    ) {
    }

    /** @param list<string> $argv */
    public static function main(string $base, array $argv): int
    {
        if (function_exists('posix_setpgid')) {
            @posix_setpgid(0, 0); // ruling 5: our children share this group
        }
        $paths = new Paths($base);
        $ops = new OperationStore($paths, static fn (): int => time());
        $env = self::dotenv($base . '/.env');
        $report = AtomicFile::readJson($paths->runnerReport()) ?? [];
        $settings = [
            'php' => PHP_BINARY,
            'composer' => getenv('COMPOSER_BINARY') ?: self::which('composer'),
            'verify_url' => $env['THALLO_UPDATE_VERIFY_URL'] ?? 'http://127.0.0.1/_update/verify',
            'verify_host' => (string) (parse_url($env['BASE_URL'] ?? '', PHP_URL_HOST) ?: ''),
            'keep_backups' => (int) ($env['THALLO_UPDATE_KEEP_BACKUPS'] ?? 3),
            'barrier_timeout' => (int) ($env['THALLO_UPDATE_BARRIER_TIMEOUT'] ?? 600),
            'child_timeout' => (int) ($env['THALLO_UPDATE_CHILD_TIMEOUT'] ?? 3600),
            'composer_timeout' => (int) ($env['THALLO_UPDATE_COMPOSER_TIMEOUT'] ?? 1800),
            'base_url' => (string) ($env['BASE_URL'] ?? ''),
            // Spec §8 step 9: opcache.revalidate_freq (from PHP-FPM's own probe) + 30 s.
            'web_settle' => 30 + (int) ($report['web']['opcache']['revalidate_freq'] ?? 2),
        ];
        $op = $ops->current();
        $log = $op !== null ? $paths->log($op['id']) : $base . '/storage/logs/updater.log';
        $identity = static fn (): array => [
            getmypid(),
            function_exists('posix_getpgid') ? (posix_getpgid(0) ?: null) : null,
        ];
        [$pid, $pgid] = $identity();
        $host = new RealHost($log, [], $pgid, $pid);
        $runner = new self($paths, $ops, new Barrier($paths), $host, $settings, $identity);
        return match ($argv[1] ?? 'tick') {
            'tick' => $runner->tick(),
            'recover' => $runner->recoverFromTerminal($argv[2] ?? ''),
            'token' => $runner->printToken(),
            'selftest' => $runner->selftest(),
            'confirm-restart' => $runner->confirmRestart(in_array('--yes', $argv, true)),
            default => 2,
        };
    }

    public function tick(): int
    {
        $this->ensureSecret();
        RunnerReport::write($this->paths, $this->host, $this->settings);
        if ($this->ops->current() === null) {
            return 0;
        }
        $this->killPoint('tick.before-lock');
        $lock = FileLock::exclusive($this->paths->runLock(), false);
        if ($lock === null) {
            return 0; // a live runner, or a descendant still holding the inherited lock, owns execution
        }
        $this->runLock = $lock;
        if ($this->host instanceof RealHost) {
            $this->host->inherit($lock); // every child keeps execution ownership while it lives
        }
        $op = $this->ops->current(); // re-read: only what is true now that we own execution counts
        [$pid, $pgid] = ($this->identity)();
        $claimed = null;
        $then = null;
        // Execution ownership alone is not enough: a descendant of the previous runner may have closed
        // its inherited descriptors and still be writing. No claim of a running or recovering
        // operation — explicit Reopen included — proceeds while the recorded group has a member.
        if (
            in_array($op['status'], [Status::RUNNING, Status::RECOVERY_REQUIRED], true)
            && Liveness::groupAlive(isset($op['pgid']) ? (int) $op['pgid'] : null)
        ) {
            $this->releaseRunLock();
            return 0;
        }
        if ($op['status'] === Status::RUNNING) {
            $claimed = $this->ops->takeOver($op['id'], (string) $op['owner'], $pid, $pgid);
            $then = fn (array $o) => $this->afterDeath($op, $o);
        } elseif ($op['status'] === Status::RECOVERY_REQUIRED && $op['recovery_request'] !== null) {
            $claimed = $this->ops->claimRecovery($op['id'], $pid, $pgid);
            $then = fn (array $o) => $this->recover((string) $o['recovering']);
        } elseif ($op['status'] === Status::REQUESTED) {
            $claimed = $this->ops->claim($op['id'], $pid, $pgid);
            $then = function (array $o): void {
                // The verification secret is rotated per operation.
                AtomicFile::write($this->paths->verifySecret(), bin2hex(random_bytes(32)) . "\n", 0640);
                $this->runFrom('preflight');
            };
        }
        if ($claimed === null) {
            $this->releaseRunLock();
            return 0;
        }
        $this->id = $claimed['id'];
        $this->owner = $claimed['owner'];
        $this->journal = null;
        $then($claimed);
        // The work is over: give up execution ownership now rather than when the process (or, in a
        // long-lived caller, the garbage collector — the runner's closures reference it) lets go.
        $this->releaseBarrier();
        $this->releaseRunLock();
        return 0;
    }

    public function killPoint(string $name): void
    {
        if ($this->onKillPoint !== null) {
            try {
                ($this->onKillPoint)($name);
            } catch (\Throwable $t) {
                throw new Killed($t);
            }
            return;
        }
        $armed = is_file($this->paths->proofMarker()) && getenv('THALLO_UPDATER_KILL_AT') === $name;
        if ($armed && function_exists('posix_kill')) {
            posix_kill(getmypid(), 9);
        }
    }

    private function runFrom(string $first): void
    {
        $op = $this->current();
        $stage = $this->paths->opDir($op['id']) . '/stage';
        $steps = array_slice(self::STEPS, (int) array_search($first, self::STEPS, true));
        try {
            foreach ($steps as $step) {
                $this->begin($step);
                match ($step) {
                    'preflight' => $this->requireNoProblems(
                        RunnerReport::write($this->paths, $this->host, $this->settings),
                    ),
                    'stage' => $this->stage($stage, $op['target']),
                    'close' => $this->close(),
                    'barrier' => $this->takeBarrier(),
                    'backup' => $this->backup($op['id']),
                    'swap' => $this->swap($op['id'], $stage),
                    'provision' => $this->provision(),
                    'verify_cli' => $this->verifyCli($op['target']),
                    'verify_web' => $this->verifyWeb($op['target']),
                    'reopen' => $this->reopen($op['id']),
                };
                $this->end($step);
                $this->killPoint($step . '.done');
            }
        } catch (Killed $k) {
            throw $k->cause;
        } catch (Fenced) {
            return; // we no longer own this operation: touch nothing
        } catch (\Throwable $e) {
            $this->onFailure($e->getMessage());
        }
    }

    private function onFailure(string $error): void
    {
        $op = $this->current();
        try {
            $this->stopDescendants(); // nothing we started may still be changing files
        } catch (\Throwable $e) {
            $this->finish(Status::RECOVERY_REQUIRED, $error . ' — ' . $e->getMessage());
            return;
        }
        if ($op['provision_started']) {
            $this->finish(Status::RECOVERY_REQUIRED, $error); // spec §10: never reopens by itself
            return;
        }
        $journal = $this->journal();
        if (!$journal->hasForwardChanges()) {
            $this->barrier->open();
            $this->releaseBarrier();
            $this->finish(Status::FAILED, $error);
            return;
        }
        try {
            $journal->resume();
            $journal->rollback();
            $this->verifyCli($op['from']);
            $this->verifyWeb($op['from']);
            $this->barrier->open();
            $this->releaseBarrier();
            $this->finish(Status::ROLLED_BACK, $error);
        } catch (Killed $k) {
            throw $k->cause;
        } catch (\Throwable $e) {
            $this->finish(Status::RECOVERY_REQUIRED, $error . ' — rollback: ' . $e->getMessage());
        }
    }

    /**
     * A dead run, now owned by this runner (spec §5; rulings 3 and 4; Review Focus 1). $dead is the
     * operation as found; reopen_ready_at is set only after every reopen prerequisite succeeded.
     */
    private function afterDeath(array $dead, array $op): void
    {
        if ($op['reopen_ready_at'] !== null) {
            $this->barrier->open();
            $this->finish(Status::SUCCEEDED, null);
            return;
        }
        try {
            $this->journal()->resume();
        } catch (JournalConflict $e) {
            $this->finish(Status::RECOVERY_REQUIRED, $e->getMessage());
            return;
        }
        if (!$op['provision_started'] && !$this->journal()->hasForwardChanges()) {
            $this->barrier->open();
            $this->finish(Status::FAILED, 'The runner stopped before changing anything (' . $dead['step'] . ').');
            return;
        }
        $this->finish(Status::RECOVERY_REQUIRED, 'The runner stopped during ' . $dead['step'] . '.');
    }

    private function recover(string $option): void
    {
        $op = $this->current();
        try {
            if ($option === 'reopen') {
                $this->barrier->open();
                $this->finish(Status::RECOVERED_BY_OWNER, null);
                return;
            }
            $this->takeBarrier();
            if ($option === 'retry_provision') {
                $this->runFrom('provision');
                return;
            }
            $journal = $this->journal();
            $journal->resume();
            $journal->rollback();
            if ($op['provision_started'] && is_array($op['provision_manifest'])) {
                ProvisionOutputs::restore(
                    $this->paths->base,
                    $this->paths->backupDir($op['id']) . '/provision',
                    $op['provision_manifest'],
                    $this->paths->opDir($op['id']) . '/restore-' . $this->host->now(),
                    $journal
                );
            }
            $this->mustChild(['thallo:update:clear-caches'], 'Clearing caches');
            $this->verifyCli($op['from']);
            $this->verifyWeb($op['from']);
            $this->releaseBarrier();
            $this->finish(Status::RECOVERY_REQUIRED, 'The code is back on ' . $op['from']
                . '. Restore the database from the backup if the update changed it, then reopen the site.');
        } catch (Killed $k) {
            throw $k->cause;
        } catch (Fenced) {
            return;
        } catch (\Throwable $e) {
            $cleanup = '';
            try {
                $this->stopDescendants(); // nothing this recovery started may outlive its verdict
            } catch (\Throwable $c) {
                $cleanup = ' — ' . $c->getMessage(); // our group stays recorded, so the next claim waits for it
            }
            $error = 'Recovery (' . $option . ') failed: ' . $e->getMessage() . $cleanup;
            $this->finish(Status::RECOVERY_REQUIRED, $error);
        }
    }

    private function stage(string $stage, string $target): void
    {
        $before = Stager::fingerprint($this->paths->base);
        $stager = new Stager(
            $this->host,
            $this->settings['php'],
            (string) $this->settings['composer'],
            $this->paths->base . '/storage/framework/composer',
            (int) ($this->settings['composer_timeout'] ?? 1800)
        );
        $stager->prepare($this->paths->base, $stage);
        if ($stager->update($stage, $target, $this->heartbeat()) !== 0) {
            throw new \RuntimeException('Composer could not install ' . $target . ' (see the log).');
        }
        $problems = $stager->verify($this->paths->base, $stage, $target, $before, $this->heartbeat());
        if ($problems !== []) {
            throw new \RuntimeException(implode(' ', $problems));
        }
    }

    private function close(): void
    {
        $this->barrier->close();
        $this->mustChild(['queue:restart'], 'Signalling the workers');
    }

    private function takeBarrier(): void
    {
        $this->barrierLock = $this->barrier->acquire(
            (int) $this->settings['barrier_timeout'],
            fn (): int => $this->host->now(),
            fn () => $this->host->sleep(1),
        );
        if ($this->barrierLock === null) {
            throw new \RuntimeException('The barrier timed out: a request, job or scheduler run did not finish within '
                . $this->settings['barrier_timeout'] . ' s.');
        }
        if ($this->host instanceof RealHost) {
            $this->host->inherit($this->barrierLock);
        }
    }

    private function backup(string $id): void
    {
        $dir = $this->paths->backupDir($id);
        $this->mustChild(['thallo:update:backup', '--to=' . $dir, '--json'], 'The database backup');
        if (!@copy($this->paths->base . '/composer.lock', $dir . '/composer.lock')) {
            throw new \RuntimeException('Cannot copy composer.lock into the backup.');
        }
        $manifest = ProvisionOutputs::snapshot($this->paths->base, $dir . '/provision'); // throws on any failed copy
        $this->update(static function (array $op) use ($dir, $manifest): array {
            $op['backup'] = ['path' => $dir];
            $op['provision_manifest'] = $manifest;
            return $op;
        });
    }

    private function swap(string $id, string $stage): void
    {
        $op = $this->paths->opDir($id);
        $j = $this->journal();
        $j->rename($this->paths->base . '/vendor', $op . '/vendor.old', 'swap.1');
        $j->rename($stage . '/vendor', $this->paths->base . '/vendor', 'swap.2');
        $j->rename($this->paths->base . '/composer.lock', $op . '/composer.lock.old', 'swap.3');
        $j->rename($stage . '/composer.lock', $this->paths->base . '/composer.lock', 'swap.4');
    }

    private function provision(): void
    {
        $this->update(static fn (array $op): array => ['provision_started' => true] + $op);
        $this->mustChild(['thallo:provision', '--no-interaction'], 'Provision');
    }

    /** This command's own exit code and stdout — never an older line of the log. */
    private function verifyCli(string $expected): void
    {
        [$code, $out] = $this->child(['thallo:update:verify', '--json']);
        if ($code !== 0) {
            throw new \RuntimeException("The command-line check exited {$code}.");
        }
        $lines = preg_split('/\R/', trim($out)) ?: [];
        $data = json_decode((string) end($lines), true);
        $got = is_array($data) ? (string) ($data['version'] ?? '') : '';
        if ($got === '' || ltrim($got, 'v') !== ltrim($expected, 'v')) {
            throw new \RuntimeException("The command line reports '{$got}', expected {$expected}.");
        }
    }

    /** Spec §8 step 9: three consecutive answers from PHP-FPM itself report $expected. */
    private function verifyWeb(string $expected): void
    {
        $secret = trim((string) @file_get_contents($this->paths->verifySecret()));
        $headers = ['x-thallo-verify' => $secret, 'Content-Type' => 'application/json'];
        if ($this->settings['verify_host'] !== '') {
            $headers['Host'] = $this->settings['verify_host'];
        }
        $deadline = $this->host->now() + (int) $this->settings['web_settle'];
        $good = 0;
        $last = '';
        while ($this->host->now() <= $deadline) {
            [$status, $body] = $this->host->http((string) $this->settings['verify_url'], $headers, '{"mode":"boot"}');
            $data = json_decode($body, true);
            $ok = $status === 200 && is_array($data)
                && ltrim((string) ($data['version'] ?? ''), 'v') === ltrim($expected, 'v')
                && ($data['missing_extensions'] ?? ['?']) === [] && ($data['container'] ?? false) === true;
            $good = $ok ? $good + 1 : 0;
            $last = "{$status} {$body}";
            if ($good >= 3) {
                return;
            }
            $this->host->sleep(1);
        }
        throw new \RuntimeException("PHP-FPM did not confirm {$expected}: {$last}");
    }

    /**
     * Every prerequisite first — caches cleared, workers signalled, no descendant left — then the
     * durable marker; after it only flag removal, barrier release and the status remain, which a
     * later tick completes if this runner dies (Review Focus 1).
     */
    private function reopen(string $id): void
    {
        $this->mustChild(['thallo:update:clear-caches'], 'Clearing caches');
        $this->mustChild(['queue:restart'], 'Signalling the workers');
        $this->stopDescendants();
        $this->update(fn (array $op): array => ['reopen_ready_at' => $this->host->now()] + $op);
        $this->killPoint('reopen.before-open');
        $this->barrier->open();
        $this->killPoint('reopen.after-open');
        $this->releaseBarrier();
        $this->killPoint('reopen.after-release');
        $this->finish(Status::SUCCEEDED, null);
        $remove = ['rm', '-rf', $this->paths->opDir($id)];
        $this->host->run($remove, $this->paths->base, [], 600, static function (): void {
        });
        $this->pruneBackups();
    }

    private function pruneBackups(): void
    {
        $dirs = glob($this->paths->base . '/storage/backups/update-*', GLOB_ONLYDIR) ?: [];
        rsort($dirs); // ids start with a UTC timestamp, so name order is age order
        foreach (array_slice($dirs, (int) $this->settings['keep_backups']) as $dir) {
            $this->host->run(['rm', '-rf', $dir], $this->paths->base, [], 600, static function (): void {
            });
        }
    }

    /** @param list<string> $args @return array{0:int,1:string} */
    private function child(array $args): array
    {
        return $this->host->runCapture(
            array_merge([$this->settings['php'], $this->paths->base . '/glueful'], $args),
            $this->paths->base,
            ['THALLO_UPDATE_CHILD' => '1'],
            (int) ($this->settings['child_timeout'] ?? 3600),
            $this->heartbeat()
        );
    }

    /** @param list<string> $args */
    private function mustChild(array $args, string $what): void
    {
        [$code] = $this->child($args);
        if ($code !== 0) {
            throw new \RuntimeException("{$what} failed (exit {$code}; see the log).");
        }
    }

    private function stopDescendants(): void
    {
        [$pid, $pgid] = ($this->identity)();
        if ($pgid !== null) {
            ChildProcess::stopGroupMembers($pgid, $pid);
        }
    }

    private function current(): array
    {
        $op = $this->ops->current();
        if ($op === null || $op['id'] !== $this->id || $op['owner'] !== $this->owner) {
            throw new Fenced('Operation changed hands');
        }
        return $op;
    }

    private function update(callable $mutate): array
    {
        return $this->ops->update((string) $this->id, (string) $this->owner, $mutate);
    }

    private function finish(string $status, ?string $error): void
    {
        $this->ops->finish((string) $this->id, (string) $this->owner, $status, $error);
    }

    private function heartbeat(): \Closure
    {
        return fn () => $this->update(fn (array $op): array => ['heartbeat_at' => $this->host->now()] + $op);
    }

    private function begin(string $step): void
    {
        $entry = ['name' => $step, 'status' => 'running', 'started_at' => $this->host->now(), 'ended_at' => null];
        $this->update(static fn (array $op): array => array_merge($op, [
            'step' => $step,
            'steps' => array_merge($op['steps'], [$entry]),
        ]));
    }

    private function end(string $step): void
    {
        $this->update(function (array $op): array {
            $last = count($op['steps']) - 1;
            $op['steps'][$last]['status'] = 'done';
            $op['steps'][$last]['ended_at'] = $this->host->now();
            return $op;
        });
    }

    private function requireNoProblems(array $report): void
    {
        $problems = array_merge(
            $report['shape_problems'],
            $report['writable'] ? [] : ['The runner cannot write the code.'],
            $report['composer_ok'] ? [] : ['Composer is missing or too old.'],
            ($report['pg_dump'] && $report['pg_restore']) ? [] : ['pg_dump or pg_restore is missing.'],
            $report['unguarded_processes'] === [] ? [] : [
                'Processes started before the guard was installed are still running: '
                    . implode(', ', array_column($report['unguarded_processes'], 'pid')) . '.',
            ],
            // The same readiness rule as admin preflight: an empty scan is not the owner's confirmation,
            // which covers the processes the scan cannot see (Task 13).
            self::restartConfirmed($report) ? [] : [
                'The restart after enabling updates has not been confirmed '
                    . '(php bin/thallo-updater confirm-restart --yes).',
            ]
        );
        if ($problems !== []) {
            throw new \RuntimeException(implode(' ', $problems));
        }
    }

    private function journal(): Journal
    {
        return $this->journal ??= new Journal(
            $this->ops,
            (string) $this->id,
            (string) $this->owner,
            fn (string $label) => $this->killPoint($label),
        );
    }

    private function ensureSecret(): void
    {
        // The probe needs a secret before any operation exists (first enablement, preflight, selftest).
        if (!is_file($this->paths->verifySecret())) {
            if (!is_dir($this->paths->dir())) {
                mkdir($this->paths->dir(), 0770, true);
            }
            AtomicFile::write($this->paths->verifySecret(), bin2hex(random_bytes(32)) . "\n", 0640);
        }
    }

    private function releaseBarrier(): void
    {
        if ($this->barrierLock !== null) {
            if ($this->host instanceof RealHost) {
                $this->host->forget($this->barrierLock); // never hand a closed handle to a later child
            }
            FileLock::release($this->barrierLock);
            $this->barrierLock = null;
        }
    }

    private function releaseRunLock(): void
    {
        if ($this->runLock !== null) {
            if ($this->host instanceof RealHost) {
                $this->host->forget($this->runLock);
            }
            FileLock::release($this->runLock);
            $this->runLock = null;
        }
    }

    /** The terminal's recovery request: file access to the site is the authority (spec §10). */
    public function recoverFromTerminal(string $flag): int
    {
        $options = ['--rollback' => 'rollback', '--retry-provision' => 'retry_provision', '--reopen' => 'reopen'];
        $option = $options[$flag] ?? null;
        $op = $this->ops->current();
        if ($option === null || $op === null) {
            fwrite(STDERR, "Usage: php bin/thallo-updater recover --rollback|--retry-provision|--reopen\n");
            return 2;
        }
        if (!$this->ops->requestRecoveryAsOwner($op['id'], $option)) {
            fwrite(STDERR, "The update is not waiting for recovery (status: {$op['status']}).\n");
            return 1;
        }
        echo "Requested {$option}; running it now.\n";
        return $this->tick();
    }

    /** A fresh recovery token for a browser that lost it (spec §10). */
    public function printToken(): int
    {
        $op = $this->ops->current();
        $token = $op !== null ? $this->ops->rotateToken($op['id']) : null;
        if ($token === null) {
            fwrite(STDERR, "No update to recover.\n");
            return 1;
        }
        $url = rtrim((string) ($this->settings['base_url'] ?? ''), '/') . '/_update';
        echo "Open {$url} and enter this token:\n{$token}\n";
        return 0;
    }

    /**
     * The owner's confirmation that everything started before the guard has been restarted
     * (Task 13). Refuses while any attributable pre-guard process remains, and without --yes.
     */
    public function confirmRestart(bool $yes): int
    {
        $installed = $this->stamp($this->paths->dir() . '/guard.installed');
        if ($installed === null) {
            fwrite(STDERR, "The update guard is not installed. Turn THALLO_ADMIN_UPDATES on and run "
                . "php glueful thallo:provision.\n");
            return 1;
        }
        $left = RunnerReport::unguardedProcesses($this->paths, $this->host, $installed);
        foreach ($left as $p) {
            echo "Started before the guard: {$p['pid']} {$p['args']}\n";
        }
        echo "This checks the web server and the PHP processes it can tie to this site. A process another user\n"
            . "started elsewhere, naming nothing of the site, cannot be seen: confirming says you restarted\n"
            . "those too.\n";
        if ($left !== []) {
            fwrite(STDERR, "Restart these, then run this again.\n");
            return 1;
        }
        if (!$yes) {
            echo "Run again with --yes to confirm that PHP-FPM (or Apache), every queue worker and every\n"
                . "long-running job of this site has been restarted.\n";
            return 1;
        }
        $user = function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '') : '';
        AtomicFile::write($this->paths->dir() . '/guard.ready', $this->host->now() . ' ' . $user . "\n", 0644);
        echo "Restart confirmed. Updating from the admin is available once preflight passes.\n";
        return 0;
    }

    /** One line per check; 0 only when every check passes (spec §2). Never closes the site. */
    public function selftest(): int
    {
        $this->ensureSecret();
        $report = RunnerReport::write($this->paths, $this->host, $this->settings);
        $base = $this->paths->base;
        $web = $report['web'];
        $includes = static fn (string $file, string $needle): bool => is_file($file)
            && str_contains((string) file_get_contents($file), $needle);
        $checks = [
            'The runner owns vendor/' => function_exists('posix_geteuid')
                && @fileowner($base . '/vendor') === posix_geteuid(),
            'The runner can write the code' => $report['writable'],
            'The guard is installed and included by public/index.php and glueful' => $report['guard_installed']
                && $includes($base . '/public/index.php', 'update-guard.php')
                && $includes($base . '/glueful', 'update-guard.php'),
            'verify.secret exists' => is_file($this->paths->verifySecret()),
            'PHP-FPM answers the probe' => is_array($web) && $web['status'] === 200,
            'PHP-FPM cannot write the code' => is_array($web) && ($web['writable_code'] ?? true) === false,
            'PHP-FPM can take the admission lock' => is_array($web) && ($web['admission_ok'] ?? false) === true,
            'PHP-FPM can write the update state folder' => is_array($web) && ($web['state_writable'] ?? false) === true,
            'The runner can write the update state folder' => is_writable($this->paths->dir()),
            'Nothing started before the guard still runs' => $report['unguarded_processes'] === [],
            'The restart after enabling was confirmed' => self::restartConfirmed($report),
            'pgrep and ps run' => $this->host->capture(['pgrep', '-x', 'no-such-process-thallo'], '/')[0] <= 1
                && $this->host->capture(['ps', '-o', 'pid=', '-p', (string) getmypid()], '/')[0] === 0,
            'Composer runs (' . RunnerReport::COMPOSER_FLOOR . ' or newer)' => $report['composer_ok'],
            'pg_dump and pg_restore run' => $report['pg_dump'] && $report['pg_restore'],
        ];
        $failed = 0;
        foreach ($checks as $label => $ok) {
            echo ($ok ? 'ok    ' : 'FAIL  ') . $label . "\n";
            $failed += $ok ? 0 : 1;
        }
        return $failed === 0 ? 0 : 1;
    }

    private static function restartConfirmed(array $report): bool
    {
        return $report['guard_installed_at'] !== null
            && $report['guard_ready_at'] !== null
            && $report['guard_ready_at'] > $report['guard_installed_at'];
    }

    private function stamp(string $file): ?int
    {
        return is_file($file) ? (int) strtok(trim((string) file_get_contents($file)), " \n") : null;
    }

    /** @return array<string,string> minimal KEY=VALUE parsing, quotes stripped; the runner loads no Dotenv */
    private static function dotenv(string $file): array
    {
        $env = [];
        foreach (is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES) ?: []) : [] as $line) {
            if (preg_match('/^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/', $line, $m)) {
                $env[$m[1]] = trim(preg_replace('/\s+#.*$/', '', $m[2]) ?? '', " \"'");
            }
        }
        return $env;
    }

    private static function which(string $program): string
    {
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            if ($dir !== '' && is_file($dir . '/' . $program) && is_executable($dir . '/' . $program)) {
                return $dir . '/' . $program;
            }
        }
        return '';
    }
}
