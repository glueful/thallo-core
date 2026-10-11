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
        if ($first) {
            // Processes started before this moment hold no admission lock: preflight waits until the
            // owner confirms a restart (php bin/thallo-updater confirm-restart --yes) made after it.
            $dir = $base . '/storage/framework/updates';
            if (!is_dir($dir)) {
                mkdir($dir, 0770, true);
            }
            AtomicFile::write($dir . '/guard.installed', time() . "\n", 0644);
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
        @unlink($base . '/storage/framework/updates/guard.installed');
        @unlink($base . '/storage/framework/updates/guard.ready');
        return 'removed';
    }
}
