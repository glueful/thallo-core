<?php

declare(strict_types=1);

namespace Thallo\Core\Updater;

/** Whole-file writes that are never seen half-written: temp file, fsync, rename. */
final class AtomicFile
{
    public static function write(string $path, string $contents, int $mode = 0640): void
    {
        $tmp = $path . '.tmp-' . getmypid() . '-' . bin2hex(random_bytes(3));
        $h = fopen($tmp, 'x');
        if ($h === false) {
            throw new \RuntimeException("Cannot write {$tmp}");
        }
        fwrite($h, $contents);
        fflush($h);
        if (function_exists('fsync')) {
            fsync($h);
        }
        fclose($h);
        chmod($tmp, $mode);
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("Cannot replace {$path}");
        }
    }

    public static function writeJson(string $path, array $data, int $mode = 0640): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        self::write($path, $json . "\n", $mode);
    }

    public static function readJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }
}
