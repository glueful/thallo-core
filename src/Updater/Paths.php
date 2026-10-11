<?php

declare(strict_types=1);

namespace Thallo\Core\Updater;

/**
 * Every file the updater reads or writes, from the site's base path. Dependency-free: this class is
 * concatenated into bin/thallo-updater and bootstrap/update-guard.php (scripts/build-updater).
 */
final class Paths
{
    public function __construct(public readonly string $base)
    {
    }

    public function dir(): string
    {
        return $this->base . '/storage/framework/updates';
    }
    public function operation(): string
    {
        return $this->dir() . '/operation.json';
    }
    public function stateLock(): string
    {
        return $this->dir() . '/state.lock';
    }
    public function runLock(): string
    {
        return $this->dir() . '/run.lock';
    }
    public function admissionLock(): string
    {
        return $this->dir() . '/admission.lock';
    }
    public function closedFlag(): string
    {
        return $this->dir() . '/closed.flag';
    }
    public function runnerReport(): string
    {
        return $this->dir() . '/runner.json';
    }
    public function verifySecret(): string
    {
        return $this->dir() . '/verify.secret';
    }
    public function proofMarker(): string
    {
        return $this->dir() . '/.proof';
    }
    public function opDir(string $id): string
    {
        return $this->dir() . '/' . $id;
    }
    public function backupDir(string $id): string
    {
        return $this->base . '/storage/backups/update-' . $id;
    }
    public function log(string $id): string
    {
        return $this->base . '/storage/logs/update-' . $id . '.log';
    }
}
