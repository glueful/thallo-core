<?php

declare(strict_types=1);

namespace Thallo\Core\Updater;

/** flock helpers. A lock lives as long as its handle (or the process); release() closes it. */
final class FileLock
{
    /** @return resource|null */
    public static function exclusive(string $path, bool $block = true)
    {
        $h = self::open($path);
        if ($h === false) {
            throw new \RuntimeException("Cannot open lock {$path}");
        }
        if (!flock($h, $block ? LOCK_EX : LOCK_EX | LOCK_NB)) {
            fclose($h);
            return null;
        }
        return $h;
    }

    /** @return resource|null|false  false: the file cannot be opened; null: held exclusively elsewhere */
    public static function shared(string $path)
    {
        // Read-only, never created: PHP-FPM may only read the admission lock (spec §16.1), and a
        // missing or unreadable file is "cannot open" (false), not "held by the runner" (null).
        $h = @fopen($path, 'r');
        if ($h === false) {
            return false;
        }
        if (!flock($h, LOCK_SH | LOCK_NB)) {
            fclose($h);
            return null;
        }
        return $h;
    }

    /**
     * Opens (creating if needed) a lock file. Two users in one group share these files — PHP-FPM and
     * the update runner — so a lock file this process creates is made group read-write; under the
     * usual 022 umask the other user could not open it.
     *
     * @return resource|false
     */
    private static function open(string $path)
    {
        $new = !file_exists($path);
        $h = @fopen($path, 'c');
        if ($h !== false && $new) {
            @chmod($path, 0660);
        }
        return $h;
    }

    /** @param resource $handle */
    public static function release($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    public static function withExclusive(string $path, callable $fn): mixed
    {
        $h = self::exclusive($path);
        try {
            return $fn();
        } finally {
            self::release($h);
        }
    }
}
