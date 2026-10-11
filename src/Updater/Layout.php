<?php

declare(strict_types=1);

namespace Thallo\Core\Updater;

/**
 * Whether the trust boundary's folders are what the runner may rely on (spec §16.1):
 *
 * - the private folder (0700) and the control folder (2750) are real directories the runner owns;
 *   the control folder is setgid, so what the runner publishes there takes the web group;
 * - the inbox is a real directory, not a link to somewhere else;
 * - no ancestor of the site lets anyone but the runner or root replace them. A world-writable
 *   ancestor is accepted only when it is sticky and the folder below it is the runner's or root's,
 *   as /tmp is.
 *
 * Everything is read with lstat, so a symbolic link anywhere in the private or control path is
 * refused. Ancestors are walked on the real path (macOS /tmp is itself a link to /private/tmp).
 */
final class Layout
{
    /** @return list<string> */
    public static function problems(Paths $paths, int $euid): array
    {
        $problems = [];
        foreach ([[$paths->privateDir(), 0700], [$paths->controlDir(), 02750]] as [$dir, $mode]) {
            $st = @lstat($dir);
            if ($st === false) {
                $problems[] = basename($dir) . ' is missing.';
                continue;
            }
            if (($st['mode'] & 0170000) !== 0040000) {
                $problems[] = basename($dir) . ' is not a real directory (a link or a file).';
                continue;
            }
            if ($st['uid'] !== $euid) {
                $problems[] = basename($dir) . ' is not owned by the user the runner runs as.';
            }
            if (($st['mode'] & 07777) !== $mode) {
                $problems[] = sprintf('%s must be mode %04o, not %04o.', basename($dir), $mode, $st['mode'] & 07777);
            }
        }
        $inbox = @lstat($paths->inbox());
        if ($inbox === false || ($inbox['mode'] & 0170000) !== 0040000) {
            $problems[] = 'storage/framework/updates must be a real directory (not a link).';
        }

        $real = realpath($paths->base);
        if ($real === false) {
            return array_merge($problems, ['The site folder cannot be resolved.']);
        }
        $child = $real . '/.thallo-update';
        for ($dir = $real;; $dir = dirname($dir)) {
            $st = @stat($dir);
            if ($st === false) {
                $problems[] = "{$dir} cannot be read.";
                break;
            }
            $trustedOwner = $st['uid'] === $euid || $st['uid'] === 0;
            $writableByOthers = ($st['mode'] & 0022) !== 0;
            if ($writableByOthers) {
                $sticky = ($st['mode'] & 01000) !== 0;
                $childSt = @lstat($child);
                $childTrusted = $childSt !== false && ($childSt['uid'] === $euid || $childSt['uid'] === 0);
                if (!$sticky || !$childTrusted || !$trustedOwner) {
                    $problems[] = "{$dir} is writable by other users, so the update folders could be replaced.";
                }
            } elseif (!$trustedOwner) {
                $problems[] = "{$dir} belongs to another user, who could replace the update folders.";
            }
            if ($dir === '/' || dirname($dir) === $dir) {
                break;
            }
            $child = $dir;
        }
        return $problems;
    }
}
