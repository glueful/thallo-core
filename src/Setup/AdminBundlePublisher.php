<?php

declare(strict_types=1);

namespace Thallo\Core\Setup;

/**
 * Publishes the admin bundle into the web root.
 *
 * The bundle ships inside core/resources/admin (after the package split: inside vendor/), and
 * the framework serves it from there at /admin. Web servers, though, serve anything under
 * public/ straight from disk and commonly carry a static-file rule that would answer 404 for
 * /admin/assets/* if the files were not real — so provision publishes a copy into public/admin.
 * The copy is derived output: refreshed on every provision, stale files removed, never edited.
 * Both locations hold identical content-hashed files, so there is no version skew.
 */
final class AdminBundlePublisher
{
    /**
     * Mirror $source into $target.
     *
     * @return array{published: int, removed: int}|null null when $source has no bundle to publish
     */
    public function publish(string $source, string $target): ?array
    {
        if (!is_file($source . '/index.html')) {
            return null;
        }

        $wanted = [];
        $published = 0;
        foreach ($this->files($source) as $relative) {
            $wanted[$relative] = true;
            $from = $source . '/' . $relative;
            $to = $target . '/' . $relative;
            $dir = dirname($to);
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \RuntimeException("Cannot create {$dir}");
            }
            if (!copy($from, $to)) {
                throw new \RuntimeException("Cannot publish {$relative} into {$target}");
            }
            $published++;
        }

        $removed = 0;
        if (is_dir($target)) {
            foreach ($this->files($target) as $relative) {
                if (!isset($wanted[$relative])) {
                    unlink($target . '/' . $relative);
                    $removed++;
                }
            }
        }

        return ['published' => $published, 'removed' => $removed];
    }

    /** @return list<string> relative file paths under $dir, sorted */
    private function files(string $dir): array
    {
        $out = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $out[] = substr((string) $file, strlen($dir) + 1);
            }
        }
        sort($out);

        return $out;
    }
}
