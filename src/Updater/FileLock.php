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

    /** @return resource|null */
    public static function shared(string $path)
    {
        $h = self::open($path);
        if ($h === false) {
            return null;
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
