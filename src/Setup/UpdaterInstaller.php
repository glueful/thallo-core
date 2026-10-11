<?php

declare(strict_types=1);

namespace Thallo\Core\Setup;

use Thallo\Core\Updater\AtomicFile;
use Thallo\Core\Updater\Status;

/**
 * Installs the update runner (bin/thallo-updater) and the guard (bootstrap/update-guard.php) into the
 * site, from the copies core ships in resources/updater (plan Task 13). Only while updating from the
 * admin is enabled: a guard on every install would cost every request an admission lock for nothing.
 * Disabled, both are removed — unless an update is open or awaiting recovery, which needs them.
 */
final class UpdaterInstaller
{
    private const FILES = [
        'thallo-updater' => ['bin/thallo-updater', 0755],
        'update-guard.php' => ['bootstrap/update-guard.php', 0644],
    ];

    /** @return 'installed-first'|'installed'|'current'|'removed'|'kept'|'absent' */
    public function sync(string $base, string $resources, bool $enabled, ?array $operation): string
    {
        $guard = $base . '/bootstrap/update-guard.php';
        if (!$enabled) {
            return $this->remove($base, $operation);
        }
        $first = !is_file($guard);
        $changed = false;
        foreach (self::FILES as $source => [$target, $mode]) {
            $contents = (string) file_get_contents($resources . '/' . $source);
            $path = $base . '/' . $target;
            if (is_file($path) && file_get_contents($path) === $contents) {
                continue;
            }
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0775, true);
            }
            AtomicFile::write($path, $contents, $mode);
            $changed = true;
        }
        $this->layout($base);
        if ($first) {
            // Processes started before this moment hold no admission lock: preflight waits until the
            // owner confirms a restart (php bin/thallo-updater confirm-restart --yes) made after it.
            AtomicFile::write($base . '/.thallo-update/guard.installed', time() . "\n", 0600);
            return 'installed-first';
        }
        return $changed ? 'installed' : 'current';
    }

    private function remove(string $base, ?array $operation): string
    {
        $present = array_filter(self::FILES, static fn (array $f): bool => is_file($base . '/' . $f[0]));
        if ($present === []) {
            return 'absent';
        }
        $status = (string) ($operation['status'] ?? '');
        if ($operation !== null && (!Status::isTerminal($status) || $status === Status::RECOVERY_REQUIRED)) {
            return 'kept';
        }
        foreach ($present as [$target]) {
            @unlink($base . '/' . $target);
        }
        @unlink($base . '/.thallo-update/guard.installed');
        @unlink($base . '/.thallo-update/guard.ready');
        return 'removed';
    }

    /**
     * The trust boundary's three folders (spec §16.1, §16.4), set up as the code owner before the guard
     * serves its first request: the private folder (0700); the control folder (2750, the web group; setgid so
     * what the runner publishes there is readable by PHP-FPM)
     * with an admission lock PHP-FPM can open read-only; the inbox (2770, the web group). The web group
     * is the group of storage/. Revision 3's files leave the inbox: none of them may be trusted there.
     */
    private function layout(string $base): void
    {
        $group = @filegroup($base . '/storage');
        $private = $base . '/.thallo-update';
        $control = $base . '/.thallo-update-control';
        $inbox = $base . '/storage/framework/updates';
        foreach ([[$private, 0700, false], [$control, 02750, true], [$inbox, 02770, true]] as [$dir, $mode, $shared]) {
            if (!is_dir($dir)) {
                mkdir($dir, 0700, true);
            }
            if ($shared && $group !== false) {
                @chgrp($dir, $group);
            }
            chmod($dir, $mode);
        }
        $lock = $control . '/admission.lock';
        if (!is_file($lock)) {
            touch($lock);
        }
        if ($group !== false) {
            @chgrp($lock, $group);
        }
        chmod($lock, 0640);
        $stale = ['operation.json', 'state.lock', 'run.lock', 'admission.lock', 'closed.flag', 'runner.json',
            'verify.secret', 'guard.installed', 'guard.ready', '.proof'];
        foreach ($stale as $name) {
            @unlink($inbox . '/' . $name);
        }
    }
}
