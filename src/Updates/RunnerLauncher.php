<?php

declare(strict_types=1);

namespace Thallo\Core\Updates;

use Thallo\Core\Updater\Paths;
use Thallo\Core\Updater\RealHost;
use Thallo\Core\Updater\RunnerReport;

/**
 * Simple mode (docs/upgrading.md): where PHP-FPM runs as the user that owns the code, PHP-FPM starts
 * the update runner itself, in the background, the way the extension store runs Composer — no cron
 * line. It also checks the server for preflight, which a cron runner does in the separated setup.
 */
final class RunnerLauncher
{
    /**
     * @param string $php a command-line php (PHP_BINARY under PHP-FPM is php-fpm itself)
     * @param \Closure(list<string>, array<string,string>): bool|null $spawn test seam: starts the detached shell
     * @param \Closure(): array<string,mixed>|null $collect test seam: the server report
     */
    public function __construct(
        private readonly string $base,
        private readonly string $php,
        private readonly ?string $composer,
        private readonly ?\Closure $spawn = null,
        private readonly ?\Closure $collect = null,
    ) {
    }

    public function available(): bool
    {
        return is_file($this->base . '/bin/thallo-updater') && ($this->spawn !== null || function_exists('proc_open'));
    }

    /** Starts `bin/thallo-updater tick` detached; true once the shell that backgrounds it exited cleanly. */
    public function launch(): bool
    {
        if (!$this->available()) {
            return false;
        }
        $command = sprintf(
            'nohup %s %s tick >> %s 2>&1 &',
            escapeshellarg($this->php),
            escapeshellarg($this->base . '/bin/thallo-updater'),
            escapeshellarg($this->base . '/storage/logs/thallo-updater.log'),
        );
        $env = [
            'THALLO_UPDATE_SIMPLE' => '1',
            'PATH' => (string) (getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
        ];
        if ($this->composer !== null) {
            $env['COMPOSER_BINARY'] = $this->composer;
        }
        if (is_string($home = getenv('HOME')) && $home !== '') {
            $env['HOME'] = $home;
        }
        $argv = ['/bin/sh', '-c', $command];
        if ($this->spawn !== null) {
            return ($this->spawn)($argv, $env);
        }
        $quiet = [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
        $proc = @proc_open($argv, $quiet, $pipes, $this->base, $env);
        return is_resource($proc) && proc_close($proc) === 0;
    }

    /**
     * What a runner tick reports, checked from PHP-FPM — the same user here. No probe: PHP-FPM asking
     * itself over HTTP could wait on its own last free worker.
     *
     * @return array<string,mixed>
     */
    public function report(): array
    {
        if ($this->collect !== null) {
            return ($this->collect)();
        }
        $paths = new Paths($this->base);
        $settings = ['php' => $this->php, 'composer' => $this->composer ?? '', 'verify_url' => ''];
        return RunnerReport::collect($paths, new RealHost($paths->privateDir() . '/logs/preflight.log', []), $settings);
    }
}
