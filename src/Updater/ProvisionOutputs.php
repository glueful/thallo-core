<?php

declare(strict_types=1);

namespace Thallo\Core\Updater;

/**
 * Every file provision can change, each with one rule (Review Focus 4). The proof (scenario 18)
 * diffs full before/after manifests of a real provision against this list; a changed, added or
 * deleted path without a rule fails it.
 */
final class ProvisionOutputs
{
    public const SNAPSHOT = ['.env', 'config/extensions.php', 'bootstrap/cache/extensions.php', 'public/admin',
        'docs/openapi.json', 'docs/index.html', 'storage/cache/style'];
    public const CLEAR = ['storage/cache/twig', 'storage/cache/container', 'storage/cache/routes_*.php'];
    public const RETAIN = [
        'bin/thallo-updater',
        'bootstrap/update-guard.php',
        'storage/conversion',
        'storage/framework/composer',
    ];
    public const RUNNER_OWNED = [
        'vendor',
        'composer.lock',
        'composer.json',
        'storage/logs',
        'storage/framework/updates',
        'storage/backups',
        '.thallo-update',
        '.thallo-update-control',
    ];

    /**
     * @return array{
     *     paths: array<string,bool>,
     *     dirs: array<string,int>,
     *     files: array<string,array{sha256:string,mode:int}>
     * }
     */
    public static function snapshot(string $base, string $to): array
    {
        $manifest = ['paths' => [], 'dirs' => [], 'files' => []];
        foreach (self::SNAPSHOT as $path) {
            $src = $base . '/' . $path;
            $manifest['paths'][$path] = file_exists($src);
            if (!$manifest['paths'][$path]) {
                continue;
            }
            foreach (self::dirs($src, $path) as $rel => $abs) {
                $mode = fileperms($abs) & 0777;
                self::mustMkdir($to . '/' . $rel);
                $manifest['dirs'][$rel] = $mode;
            }
            foreach (self::files($src, $path) as $rel => $abs) {
                $hash = @hash_file('sha256', $abs);
                if ($hash === false) {
                    throw new \RuntimeException("Cannot read {$rel} for the snapshot.");
                }
                self::mustCopy($abs, $to . '/' . $rel);
                if (hash_file('sha256', $to . '/' . $rel) !== $hash) {
                    throw new \RuntimeException("The snapshot copy of {$rel} does not match.");
                }
                $manifest['files'][$rel] = ['sha256' => $hash, 'mode' => fileperms($abs) & 0777];
            }
        }
        return $manifest;
    }

    public static function restore(
        string $base,
        string $snapshotDir,
        array $manifest,
        string $workDir,
        Journal $journal,
    ): void {
        // Only the inventory's paths, whatever the manifest says (spec §16.2).
        foreach (array_keys($manifest['paths'] ?? []) as $path) {
            if (!in_array($path, self::SNAPSHOT, true)) {
                throw new \RuntimeException("The snapshot names {$path}, which is not in the inventory.");
            }
        }
        $inInventory = static function (string $rel): bool {
            if (str_contains('/' . $rel . '/', '/../')) {
                return false;
            }
            foreach (self::SNAPSHOT as $path) {
                if ($rel === $path || str_starts_with($rel, $path . '/')) {
                    return true;
                }
            }
            return false;
        };
        foreach (array_merge(array_keys($manifest['files'] ?? []), array_keys($manifest['dirs'] ?? [])) as $rel) {
            if (!$inInventory((string) $rel)) {
                throw new \RuntimeException("The snapshot names {$rel}, which is outside the inventory.");
            }
        }
        foreach ($manifest['files'] as $rel => $meta) {
            $copy = $snapshotDir . '/' . $rel;
            if (!is_readable($copy) || hash_file('sha256', $copy) !== $meta['sha256']) {
                throw new \RuntimeException("The snapshot of {$rel} is missing or changed; nothing was restored.");
            }
        }
        foreach (array_keys($manifest['dirs']) as $rel) {
            if (!is_dir($snapshotDir . '/' . $rel)) {
                throw new \RuntimeException("The snapshot of directory {$rel} is missing; nothing was restored.");
            }
        }
        foreach ($manifest['paths'] as $path => $present) {
            $live = $base . '/' . $path;
            if (file_exists($live)) {
                $journal->rename($live, $workDir . '/replaced/' . $path, 'restore.out.' . $path);
            }
            if (!$present) {
                continue;
            }
            // Restore from a fresh copy so the backup snapshot stays whole for the owner. Directories
            // first, from the manifest (empty ones included), then files; modes are applied last so a
            // read-only directory still receives its files.
            $under = static fn (string $rel): bool => $rel === $path || str_starts_with($rel, $path . '/');
            foreach ($manifest['dirs'] as $rel => $mode) {
                if ($under($rel)) {
                    self::mustMkdir($workDir . '/restore/' . $rel);
                }
            }
            foreach ($manifest['files'] as $rel => $meta) {
                if ($under($rel)) {
                    self::mustCopy($snapshotDir . '/' . $rel, $workDir . '/restore/' . $rel);
                    if (!@chmod($workDir . '/restore/' . $rel, $meta['mode'])) {
                        throw new \RuntimeException("Cannot set the mode of {$rel}.");
                    }
                }
            }
            foreach (array_reverse($manifest['dirs'], true) as $rel => $mode) {
                if ($under($rel) && !@chmod($workDir . '/restore/' . $rel, $mode)) {
                    throw new \RuntimeException("Cannot set the mode of {$rel}.");
                }
            }
            $journal->rename($workDir . '/restore/' . $path, $live, 'restore.in.' . $path);
        }
    }

    public static function ruleFor(string $relativePath): ?string
    {
        $rules = [
            'snapshot' => self::SNAPSHOT,
            'clear' => self::CLEAR,
            'retain' => self::RETAIN,
            'runner' => self::RUNNER_OWNED,
        ];
        foreach ($rules as $rule => $paths) {
            foreach ($paths as $p) {
                $matches = str_contains($p, '*')
                    ? fnmatch($p, $relativePath)
                    : $relativePath === $p || str_starts_with($relativePath, rtrim($p, '/') . '/');
                if ($matches) {
                    return $rule;
                }
            }
        }
        return null;
    }

    /** @return iterable<string,string> relative => absolute: the directory itself and every directory under it */
    private static function dirs(string $abs, string $rel): iterable
    {
        if (!is_dir($abs)) {
            return;
        }
        yield $rel => $abs;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($it as $item) {
            if ($item->isDir()) {
                yield $rel . '/' . substr($item->getPathname(), strlen($abs) + 1) => $item->getPathname();
            }
        }
    }

    /** @return iterable<string,string> relative path => absolute path, for a file or every file under a directory */
    private static function files(string $abs, string $rel): iterable
    {
        if (is_file($abs)) {
            yield $rel => $abs;
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $item) {
            if ($item->isFile()) {
                yield $rel . '/' . substr($item->getPathname(), strlen($abs) + 1) => $item->getPathname();
            }
        }
    }

    private static function mustCopy(string $from, string $to): void
    {
        self::mustMkdir(dirname($to));
        if (!@copy($from, $to)) {
            throw new \RuntimeException("Cannot copy {$from} to {$to}.");
        }
    }

    private static function mustMkdir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create {$dir}.");
        }
    }
}
