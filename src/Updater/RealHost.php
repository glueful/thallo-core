<?php

declare(strict_types=1);

namespace Thallo\Core\Updater;

final class RealHost implements Host
{
    /**
     * @param list<resource> $inherit handles every child receives as descriptors 3, 4, …
     * $pgid/$self: the runner's process group and pid, for ChildProcess::run's timeout cleanup.
     */
    public function __construct(
        private string $log,
        private array $inherit,
        private readonly ?int $pgid = null,
        private readonly ?int $self = null,
    ) {
    }

    /** @param resource $handle */
    public function inherit($handle): void
    {
        $this->inherit[] = $handle;
    }

    /** The operation's own log once its id is known (the runner claims before it knows which). */
    public function logTo(string $log): void
    {
        if (!is_dir(dirname($log))) {
            @mkdir(dirname($log), 0700, true);
        }
        $this->log = $log;
    }

    /** @param resource $handle */
    public function forget($handle): void
    {
        $this->inherit = array_values(array_filter($this->inherit, static fn ($h): bool => $h !== $handle));
    }

    public function run(array $argv, string $cwd, array $env, int $timeout, callable $heartbeat): int
    {
        return ChildProcess::run(
            $argv,
            $cwd,
            $env,
            $this->inherit,
            $this->log,
            $heartbeat,
            $timeout,
            null,
            $this->pgid,
            $this->self,
        );
    }

    public function runCapture(array $argv, string $cwd, array $env, int $timeout, callable $heartbeat): array
    {
        $out = tempnam(sys_get_temp_dir(), 'thallo-out-');
        try {
            $code = ChildProcess::run(
                $argv,
                $cwd,
                $env,
                $this->inherit,
                $this->log,
                $heartbeat,
                $timeout,
                $out,
                $this->pgid,
                $this->self,
            );
            $stdout = (string) file_get_contents($out);
            file_put_contents($this->log, $stdout, FILE_APPEND);
            return [$code, $stdout];
        } finally {
            @unlink($out);
        }
    }

    public function capture(array $argv, string $cwd): array
    {
        $spec = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']];
        $proc = proc_open($argv, $spec, $pipes, $cwd);
        if (!is_resource($proc)) {
            return [127, ''];
        }
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        return [proc_close($proc), $out];
    }

    public function http(string $url, array $headers, string $body): array
    {
        $lines = [];
        foreach ($headers as $k => $v) {
            $lines[] = "{$k}: {$v}";
        }
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $lines),
            'content' => $body,
            'ignore_errors' => true,
            'timeout' => 20,
        ]]);
        $out = @file_get_contents($url, false, $ctx);
        $status = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+ (\d{3})#', $h, $m)) {
                $status = (int) $m[1];
            }
        }
        return [$status, is_string($out) ? $out : ''];
    }

    public function now(): int
    {
        return time();
    }

    public function sleep(int $seconds): void
    {
        sleep($seconds);
    }
}
