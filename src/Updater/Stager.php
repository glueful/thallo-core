<?php

declare(strict_types=1);

namespace Thallo\Core\Updater;

/** Spec §8 step 2: resolve and install the approved target in a copy, never touching the live tree. */
final class Stager
{
    public function __construct(
        private readonly Host $host,
        private readonly string $php,
        private readonly string $composer,
        private readonly string $composerHome,
        private readonly int $timeout = 1800, // THALLO_UPDATE_COMPOSER_TIMEOUT, via Runner settings
    ) {
    }

    public function prepare(string $base, string $stage): void
    {
        if (!is_dir($stage)) {
            mkdir($stage, 0770, true);
        }
        copy($base . '/composer.json', $stage . '/composer.json');
        copy($base . '/composer.lock', $stage . '/composer.lock');
        $copy = ['cp', '-a', $base . '/vendor', $stage . '/vendor'];
        $code = $this->host->run($copy, $base, [], $this->timeout, static function (): void {
        });
        if ($code !== 0) {
            throw new \RuntimeException('Copying vendor/ into the stage failed');
        }
        $json = json_decode((string) file_get_contents($base . '/composer.json'), true) ?: [];
        foreach (ComposerShape::autoloadRoots($json) as $root) {
            if (file_exists($base . '/' . $root)) {
                symlink($base . '/' . $root, $stage . '/' . $root);
            }
        }
    }

    public function update(string $stage, string $target, callable $heartbeat): int
    {
        return $this->host->run(
            [$this->php, $this->composer, 'update', 'glueful/thallo-core:' . $target, '--with-all-dependencies',
                '--no-interaction', '--no-progress', '--no-ansi', '--no-scripts', '--working-dir=' . $stage],
            $stage,
            ['COMPOSER_HOME' => $this->composerHome, 'THALLO_UPDATE_CHILD' => '1'],
            $this->timeout,
            $heartbeat,
        );
    }

    public static function fingerprint(string $base): array
    {
        $installed = $base . '/vendor/composer/installed.json';
        clearstatcache();
        return [
            'composer.json' => hash_file('sha256', $base . '/composer.json'),
            'composer.lock' => hash_file('sha256', $base . '/composer.lock'),
            'installed.json' => is_file($installed) ? filesize($installed) . '@' . filemtime($installed) : null,
        ];
    }

    /** @return list<string> */
    public function verify(string $base, string $stage, string $target, array $before, callable $heartbeat): array
    {
        $problems = [];
        if (hash_file('sha256', $stage . '/composer.json') !== hash_file('sha256', $base . '/composer.json')) {
            $problems[] = 'A Composer plugin rewrote composer.json in the stage.';
        }
        if (self::fingerprint($base) !== $before) {
            $problems[] = 'The live composer.json, composer.lock or vendor/ changed while staging.';
        }
        $installed = json_decode((string) @file_get_contents($stage . '/vendor/composer/installed.json'), true);
        $version = null;
        foreach (($installed['packages'] ?? $installed ?? []) as $pkg) {
            if (is_array($pkg) && ($pkg['name'] ?? '') === 'glueful/thallo-core') {
                $version = ltrim((string) ($pkg['version'] ?? ''), 'v');
            }
        }
        if ($version !== ltrim($target, 'v')) {
            $problems[] = "The stage installed glueful/thallo-core " . ($version ?? 'nothing') . ", not {$target}.";
        }
        $probe = 'require ' . var_export($stage . '/vendor/autoload.php', true) . ';'
            . 'exit(class_exists("Thallo\\\\Core\\\\Updates\\\\UpdateChecker") ? 0 : 3);';
        $loads = $this->host->run([$this->php, '-r', $probe], $stage, ['THALLO_UPDATE_CHILD' => '1'], 120, $heartbeat);
        if ($loads !== 0) {
            $problems[] = 'The staged autoloader cannot load glueful/thallo-core.';
        }
        return $problems;
    }
}
