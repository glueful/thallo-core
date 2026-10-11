<?php

declare(strict_types=1);

namespace Thallo\Core\Updater;

/** The root composer.json shapes staging supports (spec §8.1); anything else updates from the terminal. */
final class ComposerShape
{
    public const PLUGIN_ALLOWLIST = ['php-http/discovery'];

    /** @return list<string> */
    public static function problems(array $json): array
    {
        $problems = [];
        foreach ($json['repositories'] ?? [] as $repo) {
            $type = is_array($repo) ? ($repo['type'] ?? '') : '';
            $url = is_array($repo) ? (string) ($repo['url'] ?? '') : '';
            if ($type === 'path') {
                $problems[] = 'repositories: a "path" repository (symlinked packages) cannot be staged.';
            }
            if ($type === 'artifact' && !str_starts_with($url, '/')) {
                $problems[] = 'repositories: an "artifact" repository needs an absolute url.';
            }
        }
        if (isset($json['extra']['installer-paths'])) {
            $problems[] = 'extra.installer-paths: custom install paths cannot be staged.';
        }
        $vendorDir = $json['config']['vendor-dir'] ?? 'vendor';
        if ($vendorDir !== 'vendor') {
            $problems[] = 'config.vendor-dir: only the default "vendor" is supported.';
        }
        $plugins = $json['config']['allow-plugins'] ?? [];
        if ($plugins === true) {
            $problems[] = 'config.allow-plugins: "true" allows every plugin; list them instead.';
        } elseif (is_array($plugins)) {
            foreach ($plugins as $name => $allowed) {
                if ($allowed === true && !in_array($name, self::PLUGIN_ALLOWLIST, true)) {
                    $problems[] = "config.allow-plugins: {$name} is not on Thallo's plugin allowlist.";
                }
            }
        }
        foreach (self::autoloadPaths($json) as $path) {
            $outside = $path === ''
                || str_starts_with($path, '/')
                || preg_match('#(^|/)\.\.(/|$)#', $path)
                || preg_match('#^vendor(/|$)#', $path);
            if ($outside) {
                $problems[] = "autoload: \"{$path}\" must be a path inside the project and outside vendor/.";
            }
        }
        return $problems;
    }

    /** @return list<string> */
    public static function autoloadRoots(array $json): array
    {
        $roots = [];
        foreach (self::autoloadPaths($json) as $path) {
            $first = explode('/', trim($path, '/'))[0];
            if ($first !== '') {
                $roots[$first] = true;
            }
        }
        $roots = array_keys($roots);
        sort($roots);
        return $roots;
    }

    /** @return list<string> */
    private static function autoloadPaths(array $json): array
    {
        $paths = [];
        foreach (['autoload', 'autoload-dev'] as $section) {
            foreach (['psr-4', 'psr-0'] as $kind) {
                foreach ($json[$section][$kind] ?? [] as $dirs) {
                    foreach ((array) $dirs as $dir) {
                        $paths[] = (string) $dir;
                    }
                }
            }
            foreach (['classmap', 'files'] as $kind) {
                foreach ($json[$section][$kind] ?? [] as $p) {
                    $paths[] = (string) $p;
                }
            }
        }
        return $paths;
    }
}
