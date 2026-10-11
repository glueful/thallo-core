<?php

declare(strict_types=1);

namespace Thallo\Core\Updater;

/**
 * Closing the site and the barrier (spec §7). Admitted web requests and console processes hold a
 * shared flock on admission.lock for their whole life; the runner's exclusive lock is granted only
 * when none remains. closed.flag keeps new work out. Not final: tests reach admitAfterFirstCheck().
 */
class Barrier
{
    public function __construct(protected readonly Paths $paths)
    {
    }

    public function isClosed(): bool
    {
        return is_file($this->paths->closedFlag());
    }

    public function close(): void
    {
        AtomicFile::write($this->paths->closedFlag(), gmdate('c') . "\n", 0644);
    }

    public function open(): void
    {
        @unlink($this->paths->closedFlag());
    }

    /** @return resource|null */
    public function admit()
    {
        if ($this->isClosed()) {
            return null;
        }
        return $this->admitAfterFirstCheck();
    }

    /** @return resource|null */
    protected function admitAfterFirstCheck()
    {
        $h = FileLock::shared($this->paths->admissionLock());
        if ($h === null) {
            return null; // the runner holds the barrier
        }
        if ($this->isClosed()) {
            FileLock::release($h);
            return null;
        }
        return $h;
    }

    /** @return resource|null */
    public function acquire(int $timeout, \Closure $now, \Closure $sleep)
    {
        $start = $now();
        while (true) {
            $h = FileLock::exclusive($this->paths->admissionLock(), false);
            if ($h !== null) {
                return $h;
            }
            if ($now() - $start >= $timeout) {
                return null;
            }
            $sleep();
        }
    }
}
