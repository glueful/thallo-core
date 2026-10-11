<?php

declare(strict_types=1);

namespace Thallo\Core\Updater;

/** flock helpers. A lock lives as long as its handle (or the process); release() closes it. */
final class FileLock
{
    /** @return resource|null */
    public static function exclusive(string $path, bool $block = true)
    {
        $h = fopen($path, 'c');
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
        $h = @fopen($path, 'c');
        if ($h === false) {
            return null;
        }
        if (!flock($h, LOCK_SH | LOCK_NB)) {
            fclose($h);
            return null;
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
