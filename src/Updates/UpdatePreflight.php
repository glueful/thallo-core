<?php

declare(strict_types=1);

namespace Thallo\Core\Updates;

use Composer\Semver\Semver;
use Thallo\Core\Updater\AtomicFile;
use Thallo\Core\Updater\OperationStore;
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
    ) {
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
        $reasons = array_merge($reasons, $this->runnerReasons(), $this->webReasons(), $this->operationReasons());
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
        $report = AtomicFile::readJson($this->base . '/storage/framework/updates/runner.json');
        if ($report === null || ($this->now)() - (int) ($report['checked_at'] ?? 0) > self::STALE) {
            return ['runner_missing'];
        }
        $reasons = [];
        $web = $report['web'] ?? null;
        if (($report['writable'] ?? false) !== true) {
            $reasons[] = 'not_writable';
        }
        $webBlocked = is_array($web)
            && (($web['admission_ok'] ?? true) === false || ($web['state_writable'] ?? true) === false);
        if ($webBlocked) {
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
        if (!is_array($web) || (int) ($web['status'] ?? 0) !== 200) {
            $reasons[] = 'web_verify_unreachable';
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

    /** @return list<string> PHP-FPM's own view: this process is the web runtime the update must reload */
    private function webReasons(): array
    {
        $reasons = [];
        if (is_writable($this->base . '/vendor')) {
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

    /** @return list<string> */
    private function operationReasons(): array
    {
        $op = (new OperationStore(new Paths($this->base), $this->now))->current();
        if ($op === null) {
            return [];
        }
        if ($op['status'] === Status::RECOVERY_REQUIRED) {
            return ['recovery_required'];
        }
        return Status::isTerminal((string) $op['status']) ? [] : ['in_progress'];
    }
}
