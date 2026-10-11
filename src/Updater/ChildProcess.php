<?php

declare(strict_types=1);

namespace Thallo\Core\Updater;

/**
 * Runs one child of the runner (spec §3, §5). Its output goes to the operation log. The runner's
 * locks are passed as descriptors 3, 4, …, so the run lock and the barrier stay held while any
 * descendant lives. The runner polls instead of blocking on output, so a silent child still
 * heartbeats.
 */
final class ChildProcess
{
    /**
     * On timeout it never blocks: SIGTERM, up to 10 s, SIGKILL, poll until the child has exited, then
     * (with $pgid) stop and confirm every other member of the group, and only then proc_close.
     *
     * @param list<string> $argv
     * @param array<string,string> $env
     * @param list<resource> $inherit
     */
    public static function run(
        array $argv,
        string $cwd,
        array $env,
        array $inherit,
        string $log,
        callable $heartbeat,
        int $timeout,
        ?string $stdout = null,
        ?int $pgid = null,
        ?int $self = null,
    ): int {
        $spec = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $stdout ?? $log, $stdout === null ? 'a' : 'w'],
            2 => ['file', $log, 'a'],
        ];
        foreach (array_values($inherit) as $i => $handle) {
            $spec[3 + $i] = $handle;
        }
        $base = getenv();
        $proc = proc_open($argv, $spec, $pipes, $cwd, array_merge(is_array($base) ? $base : [], $env));
        if (!is_resource($proc)) {
            return 127;
        }
        $started = time();
        $lastBeat = 0;
        while (true) {
            $status = proc_get_status($proc);
            if (!$status['running']) {
                proc_close($proc);
                return $status['exitcode'];
            }
            if (time() - $lastBeat >= 5) {
                $heartbeat();
                $lastBeat = time();
            }
            if (time() - $started >= $timeout) {
                self::terminate($proc, $status['pid']);
                if ($pgid !== null) {
                    self::stopGroupMembers($pgid, $self ?? getmypid());
                }
                proc_close($proc); // the child has exited: this cannot block
                return 124;
            }
            usleep(250_000);
        }
    }

    /** SIGTERM, up to 10 s, SIGKILL, then wait until the child has exited — never a blocking call before that. */
    /** @param resource $proc */
    private static function terminate($proc, int $pid): void
    {
        proc_terminate($proc, 15);
        for ($i = 0; $i < 40 && proc_get_status($proc)['running']; $i++) {
            usleep(250_000);
        }
        if (proc_get_status($proc)['running']) {
            proc_terminate($proc, 9);
            while (proc_get_status($proc)['running']) {
                usleep(50_000);
            }
        }
    }

    public static function stopGroupMembers(int $pgid, int $self): void
    {
        $members = static function () use ($pgid, $self): array {
            $spec = [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']];
            $p = proc_open(['pgrep', '-g', (string) $pgid], $spec, $pipes);
            if (!is_resource($p)) {
                throw new \RuntimeException('pgrep is unavailable.');
            }
            $out = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $code = proc_close($p);
            if ($code > 1) { // 1: no match; anything higher: pgrep failed
                throw new \RuntimeException('Cannot list the runner\'s process group (pgrep exited ' . $code . ').');
            }
            $pids = array_map('intval', preg_split('/\s+/', trim($out)) ?: []);
            return array_values(array_filter($pids, static fn (int $p): bool => $p > 0 && $p !== $self));
        };
        foreach ($members() as $pid) {
            posix_kill($pid, 15);
        }
        for ($i = 0; $i < 40 && $members() !== []; $i++) {
            usleep(250_000);
        }
        foreach ($members() as $pid) {
            posix_kill($pid, 9);
        }
        usleep(500_000);
        if (($left = $members()) !== []) {
            throw new \RuntimeException('Processes started by the update are still running: ' . implode(', ', $left));
        }
    }
}
