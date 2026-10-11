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
        umask(0002); // storage/ is shared with the web group; the private folder's own mode keeps it private
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
        $log = $op !== null ? $paths->log($op['id']) : $paths->privateDir() . '/logs/runner.log';
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
        // The trust boundary first (spec §16.1): with an insecure layout the runner acts on nothing.
        $euid = function_exists('posix_geteuid') ? posix_geteuid() : -1;
        if (Layout::problems($this->paths, $euid) !== []) {
            RunnerReport::write($this->paths, $this->host, $this->settings);
            return 0;
        }
        $this->ensureLayout();
        $current = $this->ops->current();
        $busy = ($current !== null && !Status::isTerminal((string) $current['status'])) || $this->barrier->isClosed();
        if (!$busy) {
            // No report while an update is open or the site closed (spec §16.6): it boots the application.
            RunnerReport::write($this->paths, $this->host, $this->settings);
        }
        $this->intake();
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
        if ($this->host instanceof RealHost) {
            $this->host->logTo($this->paths->log($claimed['id']));
        }
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
                // reopen ends its own step before it records the update's outcome.
                if ($step !== 'reopen') {
                    $this->end($step);
                    $this->killPoint($step . '.done');
                }
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
        try {
            $journal->resume(); // a rename whose "done" write was lost still counts
        } catch (JournalConflict $e) {
            $this->finish(Status::RECOVERY_REQUIRED, $error . ' — ' . $e->getMessage());
            return;
        }
        if (!$journal->hasForwardChanges()) {
            $this->barrier->open();
            $this->releaseBarrier();
            $this->finish(Status::FAILED, $error);
            $this->cleanUp();
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
            $this->cleanUp();
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
            $this->removeOpDir();
            $this->pruneBackups();
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
            $this->cleanUp();
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
                $this->cleanUp();
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
            $this->paths->composerHome(),
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
        $this->end('reopen');
        $this->finish(Status::SUCCEEDED, null);
        $this->removeOpDir();
        $this->pruneBackups();
    }

    private function pruneBackups(): void
    {
        $dirs = glob($this->paths->privateDir() . '/backups/*', GLOB_ONLYDIR) ?: [];
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
            (array) ($report['layout_problems'] ?? []),
            is_array($report['web'] ?? null) && (int) ($report['web']['status'] ?? 0) === 200
                ? [] : ['PHP-FPM cannot be reached to verify a release.'],
            (int) ($report['free_bytes'] ?? 0) >= (int) ($report['needed_bytes'] ?? PHP_INT_MAX)
                ? [] : ['There is not enough free disk for the update, its backup and the rollback copy.'],
            ($report['guard_included'] ?? false) === true ? [] : ['The update guard is not installed or not included.'],
            $this->sameFilesystem() ? [] : ['vendor/ and the private update folder are on different filesystems.'],
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
            fn (string $from, string $to): bool => $this->allowedRename($from, $to),
        );
    }

    /** The runner's own sub-folders, and the verification secret the probe needs before any operation. */
    private function ensureLayout(): void
    {
        foreach (['logs', 'ops', 'backups', 'composer-home'] as $sub) {
            $dir = $this->paths->privateDir() . '/' . $sub;
            if (!is_dir($dir)) {
                @mkdir($dir, 0700, true);
            }
        }
        if (!is_file($this->paths->verifySecret()) && is_dir($this->paths->controlDir())) {
            AtomicFile::write($this->paths->verifySecret(), bin2hex(random_bytes(32)) . "\n", 0640);
        }
    }

    /**
     * Requests from the inbox (spec §16.2). Each is renamed into the private folder before it is read,
     * so PHP-FPM cannot change it afterwards, then validated: a regular file of at most 4 KB with
     * exactly the expected keys. A start request becomes an operation only when no update is open; an
     * invalid one is recorded as failed, with the reason.
     */
    private function intake(): void
    {
        $recover = $this->claimInbox($this->paths->recoverRequest(), ['option', 'token']);
        if (is_array($recover)) {
            $this->ops->requestRecovery((string) $recover['token'], (string) $recover['option']);
        }
        $op = $this->ops->current();
        if ($op !== null && (!Status::isTerminal((string) $op['status']))) {
            return; // a start request waits in the inbox (and can still be cancelled) until none is open
        }
        $request = $this->claimInbox(
            $this->paths->startRequest(),
            ['target', 'from', 'requested_by', 'requested_at', 'token_hash'],
        );
        if ($request === null) {
            return;
        }
        // Not even a request: record the refusal (preflight shows it), trusting nothing in the file.
        $problem = is_string($request) ? $request : $this->requestProblem($request);
        $request = is_array($request) ? $request : ['target' => '', 'token_hash' => '', 'requested_by' => ''];
        $tokenHash = preg_match('/^[0-9a-f]{64}$/', (string) $request['token_hash']) === 1
            ? (string) $request['token_hash'] : hash('sha256', random_bytes(16));
        $by = preg_match('/^[A-Za-z0-9._@-]{1,64}$/', (string) $request['requested_by']) === 1
            ? (string) $request['requested_by'] : 'unknown';
        $target = $problem === null ? (string) $request['target'] : 'invalid';
        $created = $this->ops->start($target, $this->liveVersion() ?? 'unknown', $by, $tokenHash);
        if ($problem !== null) {
            [$pid, $pgid] = ($this->identity)();
            $claimed = $this->ops->claim($created['id'], $pid, $pgid);
            if ($claimed !== null) {
                $this->ops->finish($claimed['id'], $claimed['owner'], Status::FAILED, 'Request refused: ' . $problem);
            }
        }
    }

    /**
     * @param list<string> $keys
     * @return array<string,string>|string|null the request, why it is not one, or null when none was filed
     */
    private function claimInbox(string $path, array $keys): array|string|null
    {
        if (!file_exists($path) && !is_link($path)) {
            return null;
        }
        $mine = $this->paths->privateDir() . '/inbox-' . bin2hex(random_bytes(6));
        if (!@rename($path, $mine)) {
            return null;
        }
        $invalid = 'not a valid request file.';
        try {
            $st = @lstat($mine);
            if ($st === false || ($st['mode'] & 0170000) !== 0100000 || $st['size'] > 4096) {
                return $invalid; // a link, a directory or anything else that is not a small regular file
            }
            $data = json_decode((string) file_get_contents($mine), true);
            if (!is_array($data)) {
                return $invalid;
            }
            $given = array_keys($data);
            sort($given);
            $wanted = $keys;
            sort($wanted);
            if ($given !== $wanted) {
                return $invalid;
            }
            foreach ($data as $value) {
                if (!is_string($value)) {
                    return $invalid;
                }
            }
            return $data;
        } finally {
            if (is_dir($mine) && !is_link($mine)) {
                $this->host->run(['rm', '-rf', $mine], $this->paths->privateDir(), [], 60, static function (): void {
                });
            } else {
                @unlink($mine);
            }
        }
    }

    /** @param array<string,string> $request */
    private function requestProblem(array $request): ?string
    {
        $target = $request['target'];
        if (preg_match('/^\d+\.\d+\.\d+(-(alpha|beta|RC)\.?\d+)?$/', $target) !== 1) {
            return 'the target is not a release version.';
        }
        if (preg_match('/^[0-9a-f]{64}$/', $request['token_hash']) !== 1) {
            return 'the token is malformed.';
        }
        $live = $this->liveVersion();
        if ($live === null) {
            return 'the installed version cannot be read from composer.lock.';
        }
        if (!version_compare($target, $live, '>')) {
            return "{$target} is not newer than the installed {$live}.";
        }
        return null;
    }

    /** The installed glueful/thallo-core, from the live composer.lock (the code owner's file). */
    private function liveVersion(): ?string
    {
        $lock = json_decode((string) @file_get_contents($this->paths->base . '/composer.lock'), true);
        foreach ((array) ($lock['packages'] ?? []) as $package) {
            if (is_array($package) && ($package['name'] ?? '') === 'glueful/thallo-core') {
                return ltrim((string) ($package['version'] ?? ''), 'v');
            }
        }
        return null;
    }

    /**
     * The journal's fixed set (spec §16.2): the four swap renames and the inventory's restore renames,
     * in either direction. Nothing else, whatever any file says.
     */
    private function allowedRename(string $from, string $to): bool
    {
        $op = $this->paths->opDir((string) $this->id);
        $base = $this->paths->base;
        if (str_contains($from . $to, '/../') || str_ends_with($from, '/..') || str_ends_with($to, '/..')) {
            return false;
        }
        $pairs = [
            [$base . '/vendor', $op . '/vendor.old'],
            [$op . '/stage/vendor', $base . '/vendor'],
            [$base . '/composer.lock', $op . '/composer.lock.old'],
            [$op . '/stage/composer.lock', $base . '/composer.lock'],
        ];
        foreach ($pairs as [$a, $b]) {
            if (($from === $a && $to === $b) || ($from === $b && $to === $a)) {
                return true;
            }
        }
        foreach (ProvisionOutputs::SNAPSHOT as $path) {
            $live = $base . '/' . $path;
            $work = '#^' . preg_quote($op, '#') . '/restore-\d+/(replaced|restore)/' . preg_quote($path, '#') . '$#';
            $out = $from === $live && preg_match($work, $to) === 1;
            $back = $to === $live && preg_match($work, $from) === 1;
            if ($out || $back) {
                return true;
            }
        }
        return false;
    }

    /**
     * Remove the operation's folder (a whole copy of vendor/) once nothing in it is live (spec §16.5).
     * Called after the outcome is recorded, so it reads the journal rather than settling it: an entry
     * still intended, or a forward rename not reversed, keeps the folder for the owner's recovery.
     */
    private function cleanUp(): void
    {
        $journal = (array) ($this->ops->current()['journal'] ?? []);
        foreach ($journal as $e) {
            if (($e['phase'] ?? '') === 'intended') {
                return;
            }
        }
        if (!$this->journal()->hasForwardChanges()) {
            $this->removeOpDir();
            $this->pruneBackups();
        }
    }

    private function removeOpDir(): void
    {
        $dir = $this->paths->opDir((string) $this->id);
        $this->host->run(['rm', '-rf', $dir], $this->paths->base, [], 600, static function (): void {
        });
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
        $installed = $this->stamp($this->paths->guardInstalled());
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
        AtomicFile::write($this->paths->guardReady(), $this->host->now() . ' ' . $user . "\n", 0600);
        echo "Restart confirmed. Updating from the admin is available once preflight passes.\n";
        return 0;
    }

    /** One line per check; 0 only when every check passes (spec §2). Never closes the site. */
    public function selftest(): int
    {
        $this->ensureLayout();
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
            'The update folders are private and cannot be replaced' => ($report['layout_problems'] ?? ['?']) === [],
            'PHP-FPM cannot write the control folder' => is_array($web) && ($web['control_writable'] ?? true) === false,
            'PHP-FPM cannot read the private folder' => is_array($web) && ($web['private_readable'] ?? true) === false,
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

    private function sameFilesystem(): bool
    {
        $vendor = @stat($this->paths->base . '/vendor');
        $private = @stat($this->paths->privateDir());
        return $vendor !== false && $private !== false && $vendor['dev'] === $private['dev'];
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
