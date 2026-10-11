<?php

declare(strict_types=1);

namespace Thallo\Core\Updater;

/**
 * What only the runner's user can know (spec §4), written by every tick to runner.json for the
 * admin preflight to read: can it write the code, run Composer and pg_dump, is the Composer project
 * a supported shape, is there room, is the guard installed and the restart after it confirmed, does
 * anything started before the guard still run, and does PHP-FPM answer the probe.
 */
final class RunnerReport
{
    /** Composer that understands temporary inline constraints (`composer update vendor/pkg:version`). */
    public const COMPOSER_FLOOR = '2.2';
    private const DISK_EVERY = 1800;
    private const MARGIN = 268_435_456;

    public static function write(Paths $paths, Host $host, array $settings): array
    {
        $report = self::collect($paths, $host, $settings);
        if (!is_dir($paths->dir())) {
            mkdir($paths->dir(), 0770, true);
        }
        AtomicFile::writeJson($paths->runnerReport(), $report, 0644);
        return $report;
    }

    public static function collect(Paths $paths, Host $host, array $settings): array
    {
        $base = $paths->base;
        $previous = AtomicFile::readJson($paths->runnerReport()) ?? [];
        $now = $host->now();
        $user = function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? null) : null;
        $composer = (string) ($settings['composer'] ?? '');
        $composerVersion = null;
        if ($composer !== '') {
            $command = array_merge(Stager::composerCommand((string) $settings['php'], $composer), ['--version']);
            [$code, $out] = $host->capture($command, $base);
            if ($code === 0 && preg_match('/Composer (?:version )?(\d+\.\d+(?:\.\d+)?)/', $out, $m)) {
                $composerVersion = $m[1];
            }
        }
        $json = json_decode((string) @file_get_contents($base . '/composer.json'), true);
        $installedAt = self::stamp($paths->dir() . '/guard.installed');
        $readyAt = self::stamp($paths->dir() . '/guard.ready');
        $disk = self::disk($paths, $host, $settings, $previous, $now);

        return [
            'checked_at' => $now,
            'user' => $user,
            'uid' => function_exists('posix_geteuid') ? posix_geteuid() : null,
            'composer' => $composer !== '' ? $composer : null,
            'composer_version' => $composerVersion,
            'composer_ok' => $composerVersion !== null && version_compare($composerVersion, self::COMPOSER_FLOOR, '>='),
            'pg_dump' => $host->capture(['pg_dump', '--version'], $base)[0] === 0,
            'pg_restore' => $host->capture(['pg_restore', '--version'], $base)[0] === 0,
            'writable' => self::writable($base),
            'shape_problems' => is_array($json) ? ComposerShape::problems($json) : ['composer.json cannot be read.'],
            'free_bytes' => (int) (@disk_free_space($base) ?: 0),
            'needed_bytes' => $disk['needed_bytes'],
            'disk_computed_at' => $disk['computed_at'],
            'guard_installed' => is_file($base . '/bootstrap/update-guard.php'),
            'guard_installed_at' => $installedAt,
            'guard_ready_at' => $readyAt,
            'unguarded_processes' => self::unguardedProcesses($paths, $host, $installedAt),
            'web' => self::probe($paths, $host, $settings),
        ];
    }

    /**
     * Every process attributable to the site that started before the guard was installed (Task 13):
     * web servers outright, PHP command-line processes by their working directory or arguments.
     *
     * @return list<array{pid:int,args:string}>
     */
    public static function unguardedProcesses(Paths $paths, Host $host, ?int $installedAt): array
    {
        if ($installedAt === null) {
            return [];
        }
        [$code, $out] = $host->capture(['ps', '-eo', 'pid=,etime=,user=,args='], '/');
        if ($code !== 0) {
            return [];
        }
        $now = $host->now();
        $self = getmypid();
        $found = [];
        foreach (preg_split('/\R/', trim($out)) ?: [] as $line) {
            if (!preg_match('/^\s*(\d+)\s+(\S+)\s+(\S+)\s+(.+)$/', $line, $m)) {
                continue;
            }
            [$pid, $elapsed, $args] = [(int) $m[1], self::seconds($m[2]), $m[4]];
            $skip = $pid === $self
                || $elapsed === null
                || $now - $elapsed >= $installedAt
                || str_contains($args, 'thallo-updater');
            if ($skip) {
                continue;
            }
            if (self::attributable($pid, $args, $paths->base)) {
                $found[] = ['pid' => $pid, 'args' => $args];
            }
        }
        return $found;
    }

    private static function attributable(int $pid, string $args, string $base): bool
    {
        $program = basename(strtok($args, ' ') ?: '');
        if (
            str_starts_with($args, 'php-fpm') || str_starts_with($program, 'php-fpm')
            || in_array($program, ['httpd', 'apache2', 'frankenphp'], true)
        ) {
            return true; // a single-host install: the web server must have been restarted
        }
        if (!preg_match('/^php(\d|\.|$)/', $program)) {
            return false;
        }
        if (str_contains($args, $base)) {
            return true;
        }
        $cwd = @readlink('/proc/' . $pid . '/cwd');
        if (is_string($cwd)) {
            return $cwd === $base;
        }
        return str_contains($args, 'glueful') || str_contains($args, 'thallo');
    }

    /** `ps` etime: [[dd-]hh:]mm:ss */
    private static function seconds(string $etime): ?int
    {
        if (!preg_match('/^(?:(\d+)-)?(?:(\d+):)?(\d+):(\d+)$/', $etime, $m)) {
            return null;
        }
        return ((int) ($m[1] ?: 0)) * 86400 + ((int) ($m[2] ?: 0)) * 3600 + ((int) $m[3]) * 60 + (int) $m[4];
    }

    private static function writable(string $base): bool
    {
        foreach (['vendor', 'composer.json', 'composer.lock'] as $path) {
            if (!is_writable($base . '/' . $path)) {
                return false;
            }
        }
        return !is_dir($base . '/public/admin') || is_writable($base . '/public/admin');
    }

    private static function stamp(string $file): ?int
    {
        if (!is_file($file)) {
            return null;
        }
        return (int) strtok(trim((string) file_get_contents($file)), " \n");
    }

    /** @return array{needed_bytes:?int, computed_at:?int} recomputed at most every 30 minutes */
    private static function disk(Paths $paths, Host $host, array $settings, array $previous, int $now): array
    {
        $at = isset($previous['disk_computed_at']) ? (int) $previous['disk_computed_at'] : null;
        if ($at !== null && $now - $at < self::DISK_EVERY && isset($previous['needed_bytes'])) {
            return ['needed_bytes' => (int) $previous['needed_bytes'], 'computed_at' => $at];
        }
        $kb = static function (string $path) use ($host): int {
            [$code, $out] = $host->capture(['du', '-sk', $path], '/');
            return $code === 0 ? (int) strtok(trim($out), "\t ") : 0;
        };
        $needed = $kb($paths->base . '/vendor') * 1024 * 2;
        foreach (ProvisionOutputs::SNAPSHOT as $path) {
            if (file_exists($paths->base . '/' . $path)) {
                $needed += $kb($paths->base . '/' . $path) * 1024;
            }
        }
        [$code, $out] = $host->capture(
            [
                'env',
                'THALLO_UPDATE_CHILD=1',
                (string) $settings['php'],
                $paths->base . '/glueful',
                'thallo:update:backup',
                '--estimate',
                '--json',
            ],
            $paths->base,
        );
        $lines = preg_split('/\R/', trim($out)) ?: [];
        $estimate = json_decode((string) end($lines), true);
        $database = $code === 0 && is_array($estimate) ? (int) ($estimate['database_bytes'] ?? 0) : 0;
        $needed += (int) round($database * 1.2);
        return ['needed_bytes' => $needed + self::MARGIN, 'computed_at' => $now];
    }

    /** @return array<string,mixed>|null PHP-FPM's view through the guard (spec §8 step 9); null: unreachable */
    private static function probe(Paths $paths, Host $host, array $settings): ?array
    {
        $secret = is_file($paths->verifySecret()) ? trim((string) file_get_contents($paths->verifySecret())) : '';
        if ($secret === '' || ($settings['verify_url'] ?? '') === '') {
            return null;
        }
        $headers = ['x-thallo-verify' => $secret, 'Content-Type' => 'application/json'];
        if (($settings['verify_host'] ?? '') !== '') {
            $headers['Host'] = (string) $settings['verify_host'];
        }
        [$status, $body] = $host->http((string) $settings['verify_url'], $headers, '{"mode":"probe"}');
        if ($status === 0) {
            return null;
        }
        $data = json_decode($body, true);
        return ['status' => $status] + (is_array($data) ? $data : []);
    }
}
