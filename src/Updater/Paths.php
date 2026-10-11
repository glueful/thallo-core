<?php

declare(strict_types=1);

namespace Thallo\Core\Updater;

/**
 * Every file the updater reads or writes, from the site's base path (spec §16.1). Three locations:
 *
 * - private (`.thallo-update/`, code owner, 0700): everything the runner trusts;
 * - control (`.thallo-update-control/`, code owner + the web group, 0750): what the runner publishes
 *   and PHP-FPM only reads — status, the closed flag, the admission lock, the verification secret;
 * - inbox (`storage/framework/updates/`, web-writable): requests, which the runner treats as untrusted.
 *
 * Dependency-free: this class is concatenated into bin/thallo-updater and bootstrap/update-guard.php.
 */
final class Paths
{
    public function __construct(public readonly string $base)
    {
    }

    public function privateDir(): string
    {
        return $this->base . '/.thallo-update';
    }

    public function controlDir(): string
    {
        return $this->base . '/.thallo-update-control';
    }

    public function inbox(): string
    {
        return $this->base . '/storage/framework/updates';
    }

    // ---- private -----------------------------------------------------------------------------

    public function operation(): string
    {
        return $this->privateDir() . '/operation.json';
    }

    public function stateLock(): string
    {
        return $this->privateDir() . '/state.lock';
    }

    public function runLock(): string
    {
        return $this->privateDir() . '/run.lock';
    }

    public function guardInstalled(): string
    {
        return $this->privateDir() . '/guard.installed';
    }

    public function guardReady(): string
    {
        return $this->privateDir() . '/guard.ready';
    }

    public function composerHome(): string
    {
        return $this->privateDir() . '/composer-home';
    }

    public function proofMarker(): string
    {
        return $this->privateDir() . '/.proof';
    }

    public function opDir(string $id): string
    {
        return $this->privateDir() . '/ops/' . $id;
    }

    public function backupDir(string $id): string
    {
        return $this->privateDir() . '/backups/' . $id;
    }

    public function log(string $id): string
    {
        return $this->privateDir() . '/logs/' . $id . '.log';
    }

    // ---- control (runner writes, PHP-FPM reads) ----------------------------------------------

    public function status(): string
    {
        return $this->controlDir() . '/status.json';
    }

    public function admissionLock(): string
    {
        return $this->controlDir() . '/admission.lock';
    }

    public function closedFlag(): string
    {
        return $this->controlDir() . '/closed.flag';
    }

    public function runnerReport(): string
    {
        return $this->controlDir() . '/runner.json';
    }

    public function verifySecret(): string
    {
        return $this->controlDir() . '/verify.secret';
    }

    // ---- inbox (PHP-FPM writes, the runner validates) ----------------------------------------

    public function startRequest(): string
    {
        return $this->inbox() . '/start.json';
    }

    public function recoverRequest(): string
    {
        return $this->inbox() . '/recover.json';
    }
}
