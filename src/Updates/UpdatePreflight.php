<?php

declare(strict_types=1);

namespace Thallo\Core\Updates;

use Composer\Semver\Semver;
use Thallo\Core\Updater\AtomicFile;
use Thallo\Core\Updater\Paths;
use Thallo\Core\Updater\Status;

/**
 * Whether the admin's Update button may run (spec §4), seen from PHP-FPM. The checks only the
 * runner's user can make come from runner.json, which every runner tick refreshes; a report older
 * than five minutes means no runner is scheduled.
 */
final class UpdatePreflight
{
    private const STALE = 300;
    private const RUNTIMES = ['fpm-fcgi', 'apache2handler', 'cgi-fcgi'];

    /**
     * @param array{enabled?: bool} $settings thallo.admin_updates
     * @param array<string,mixed> $status UpdateStatus::toArray()
     */
    public function __construct(
        private readonly array $settings,
        private readonly array $status,
        private readonly string $base,
        private readonly \Closure $ini,
        private readonly \Closure $sapi,
        private readonly \Closure $now,
        private readonly ?RunnerLauncher $launcher = null,
    ) {
    }

    /**
     * Simple mode (docs/upgrading.md): PHP-FPM runs as the user that owns the code — it can write the
     * code and the private update folder. Nothing separates the two there, so PHP-FPM checks the server
     * and starts the runner itself. Code it can write without the private folder is the separated setup
     * gone wrong, and stays refused.
     */
    public function simple(): bool
    {
        $private = (new Paths($this->base))->privateDir();
        return is_writable($this->base . '/vendor') && is_dir($private) && is_writable($private);
    }

    /** Simple mode's Update: start the runner now, for the request just filed. */
    public function launch(): bool
    {
        return $this->launcher !== null && $this->launcher->launch();
    }

    /** @return array{ready: bool, current: ?string, target: ?string, reasons: list<string>} */
    public function check(): array
    {
        $current = isset($this->status['current']) ? (string) $this->status['current'] : null;
        $latest = isset($this->status['latest']) ? (string) $this->status['latest'] : null;
        $available = (bool) ($this->status['available'] ?? false);
        if (!($this->settings['enabled'] ?? false)) {
            return ['ready' => false, 'current' => $current, 'target' => $latest, 'reasons' => ['disabled']];
        }

        $reasons = [];
        if ($available && $latest !== null && !$this->allowedByRoot($latest)) {
            $reasons[] = 'outside_constraint';
        }
        $simple = $this->simple();
        $reasons = array_merge(
            $reasons,
            $simple ? $this->simpleReasons() : $this->runnerReasons(),
            $this->webReasons($simple),
            $this->operationReasons(),
        );
        $reasons = array_values(array_unique($reasons));

        return [
            'ready' => $available && $reasons === [],
            'current' => $current,
            'target' => $latest,
            'reasons' => $reasons,
        ];
    }

    private function allowedByRoot(string $latest): bool
    {
        $json = json_decode((string) @file_get_contents($this->base . '/composer.json'), true);
        $constraint = is_array($json) ? ($json['require']['glueful/thallo-core'] ?? null) : null;
        if (!is_string($constraint) || $constraint === '') {
            return false;
        }
        try {
            return Semver::satisfies($latest, $constraint);
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return list<string> */
    private function runnerReasons(): array
    {
        $report = AtomicFile::readJson((new Paths($this->base))->runnerReport());
        if ($report === null || ($this->now)() - (int) ($report['checked_at'] ?? 0) > self::STALE) {
            return ['runner_missing'];
        }
        $reasons = $this->serverReasons($report);
        $web = $report['web'] ?? null;
        $webBlocked = is_array($web)
            && (($web['admission_ok'] ?? true) === false || ($web['state_writable'] ?? true) === false);
        if ($webBlocked) {
            $reasons[] = 'not_writable';
        }
        if (!is_array($web) || (int) ($web['status'] ?? 0) !== 200) {
            $reasons[] = 'web_verify_unreachable';
        }
        if (($report['layout_problems'] ?? []) !== []) {
            $reasons[] = 'insecure_layout';
        }
        if (($report['unguarded_processes'] ?? []) !== []) {
            $reasons[] = 'unguarded_processes';
        }
        $installed = $report['guard_installed_at'] ?? null;
        $ready = $report['guard_ready_at'] ?? null;
        if ($installed === null || $ready === null || (int) $ready <= (int) $installed) {
            $reasons[] = 'restart_required';
        }
        return $reasons;
    }

    /**
     * Simple mode: the server checked from PHP-FPM itself, kept for five minutes in the private folder.
     * Only what protects the site counts — the code writable, Composer, the backup, the disk.
     *
     * @return list<string>
     */
    private function simpleReasons(): array
    {
        if ($this->launcher === null || !$this->launcher->available()) {
            return ['launch_unavailable'];
        }
        $file = (new Paths($this->base))->simpleReport();
        $report = AtomicFile::readJson($file);
        if ($report === null || ($this->now)() - (int) ($report['checked_at'] ?? 0) > self::STALE) {
            $report = $this->launcher->report();
            AtomicFile::writeJson($file, $report, 0600);
        }
        return $this->serverReasons($report);
    }

    /**
     * @param array<string,mixed> $report
     * @return list<string>
     */
    private function serverReasons(array $report): array
    {
        $reasons = [];
        if (($report['writable'] ?? false) !== true) {
            $reasons[] = 'not_writable';
        }
        if (($report['composer_ok'] ?? false) !== true) {
            $reasons[] = 'composer_missing';
        }
        if (($report['shape_problems'] ?? []) !== []) {
            $reasons[] = 'composer_config_unsupported';
        }
        if (($report['pg_dump'] ?? false) !== true || ($report['pg_restore'] ?? false) !== true) {
            $reasons[] = 'backup_unavailable';
        }
        if ((int) ($report['free_bytes'] ?? 0) < (int) ($report['needed_bytes'] ?? PHP_INT_MAX)) {
            $reasons[] = 'low_disk';
        }
        return $reasons;
    }

    /** @return list<string> PHP-FPM's own view: this process is the web runtime the update must reload */
    private function webReasons(bool $simple): array
    {
        $reasons = [];
        if (!$simple && is_writable($this->base . '/vendor')) {
            $reasons[] = 'web_can_write_code';
        }
        if (!$this->guardIncluded()) {
            $reasons[] = 'guard_missing';
        }
        if ((string) ($this->ini)('opcache.preload') !== '') {
            $reasons[] = 'opcache_preload';
        }
        $opcacheOn = in_array(strtolower((string) ($this->ini)('opcache.enable')), ['1', 'on', 'true'], true);
        $validates = strtolower((string) ($this->ini)('opcache.validate_timestamps'));
        if ($opcacheOn && in_array($validates, ['0', 'off', 'false', ''], true)) {
            $reasons[] = 'opcache_frozen';
        }
        $persistent = getenv('FRANKENPHP_WORKER') !== false || getenv('RR_MODE') !== false;
        if (!in_array((string) ($this->sapi)(), self::RUNTIMES, true) || $persistent) {
            $reasons[] = 'persistent_runtime';
        }
        return $reasons;
    }

    private function guardIncluded(): bool
    {
        if (!is_file($this->base . '/bootstrap/update-guard.php')) {
            return false;
        }
        foreach (['public/index.php', 'glueful'] as $entry) {
            $source = (string) @file_get_contents($this->base . '/' . $entry);
            if (!str_contains($source, 'update-guard.php')) {
                return false;
            }
        }
        return true;
    }

    /**
     * What the runner has published (status.json in the control folder) and what waits in the inbox.
     * Both are display-level facts here: the runner decides again from its own records.
     *
     * @return list<string>
     */
    private function operationReasons(): array
    {
        $paths = new Paths($this->base);
        if (is_file($paths->startRequest())) {
            return ['in_progress'];
        }
        $status = (string) ((AtomicFile::readJson($paths->status()) ?? [])['operation']['status'] ?? '');
        if ($status === '') {
            return [];
        }
        if ($status === Status::RECOVERY_REQUIRED) {
            return ['recovery_required'];
        }
        return Status::isTerminal($status) ? [] : ['in_progress'];
    }
}
