<?php

declare(strict_types=1);

namespace Thallo\Core\Capabilities\Console;

use Glueful\Bootstrap\ApplicationContext;

/**
 * Runs `php glueful …` as a child process and streams its output line by line. An activation's
 * engine provider only boots in a process started after the engine step, so the CLI continues in
 * one of these instead of verifying a boot it changed itself.
 */
final class FreshProcess
{
    public function __construct(private readonly ApplicationContext $context)
    {
    }

    /**
     * @param list<string> $args
     * @param callable(string): void $line
     * @return int the child's exit code
     */
    public function glueful(array $args, callable $line): int
    {
        $base = $this->context->getBasePath();
        $proc = proc_open(
            [PHP_BINARY, $base . '/glueful', ...$args, '--no-interaction'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
            $base,
        );
        if (!is_resource($proc)) {
            $line('Could not start a fresh process: php glueful ' . implode(' ', $args));
            return 1;
        }
        fclose($pipes[0]);
        while (($text = fgets($pipes[1])) !== false) {
            $line(rtrim($text, "\r\n"));
        }
        fclose($pipes[1]);
        return proc_close($proc);
    }
}
