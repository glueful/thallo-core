<?php

declare(strict_types=1);

namespace Thallo\Core\Setup\Doctor;

use Glueful\Installer\ConnectionTester;
use Glueful\Installer\DatabaseConfig;
use Glueful\Installer\EnvWriter;

/**
 * First-run environment checks. Two phases:
 *  - preflight(): no DB credentials needed; runs BEFORE any prompt.
 *  - reachability(): needs a DatabaseConfig; runs after creds exist (or against existing env).
 *
 * Runtime facts (PHP version, loaded extensions, base path) are injected so every branch is
 * unit-testable. `thallo:setup` calls preflight() before prompting; `thallo:doctor` calls both.
 */
final class Doctor
{
    private const MIN_PHP = '8.3.0';
    private const REQUIRED_EXTENSIONS = ['pdo_pgsql'];

    /**
     * @param list<string> $loadedExtensions
     * @param (\Closure(string): ?int)|null $httpProbe GET a URL, return the final HTTP status or
     *        null when unreachable; defaults to a short-timeout stream request. Injected for tests.
     */
    public function __construct(
        private readonly string $basePath,
        private readonly string $phpVersion,
        private readonly array $loadedExtensions,
        private readonly ?\Closure $httpProbe = null,
    ) {
    }

    /** @return list<Check> */
    public function preflight(): array
    {
        $checks = [];

        $checks[] = version_compare($this->phpVersion, self::MIN_PHP, '>=')
            ? Check::ok('php', "PHP {$this->phpVersion} (>= " . self::MIN_PHP . ').')
            : Check::fail('php', "PHP {$this->phpVersion} is below the required " . self::MIN_PHP . '.');

        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            $checks[] = in_array($ext, $this->loadedExtensions, true)
                ? Check::ok("ext:{$ext}", "Extension {$ext} loaded.")
                : Check::fail("ext:{$ext}", "Required PHP extension {$ext} is not loaded.");
        }

        $checks[] = $this->envTargetCheck();
        $checks[] = $this->writableStorageCheck();
        $checks[] = $this->keysCheck();
        $environment = $this->environmentCheck();
        $assetRouting = $this->assetRoutingCheck();
        if ($assetRouting !== null) {
            $checks[] = $assetRouting;
        }
        if ($environment !== null) {
            $checks[] = $environment;
        }

        return $checks;
    }

    public function reachability(DatabaseConfig $config, ConnectionTester $tester): Check
    {
        $result = $tester->test($config);

        return $result->ok
            ? Check::ok('database', "Connected to {$config->host}:{$config->port}/{$config->database}.")
            : Check::fail('database', $result->message);
    }

    /**
     * The `.env` we will WRITE must be reachable: if it exists it must be writable; if it does not
     * exist, the project root must be writable AND .env.example must be readable (the framework
     * Installer creates .env from .env.example). "keys present" is intentionally NOT checked here —
     * Layer 1 generates them.
     */
    private function envTargetCheck(): Check
    {
        $env = $this->basePath . '/.env';
        if (is_file($env)) {
            return is_writable($env)
                ? Check::ok('env-target', '.env is writable.')
                : Check::fail('env-target', '.env exists but is not writable.');
        }

        $example = $this->basePath . '/.env.example';
        if (!is_file($example) || !is_readable($example)) {
            return Check::fail('env-target', '.env is absent and .env.example is missing/unreadable.');
        }
        if (!is_writable($this->basePath)) {
            return Check::fail('env-target', 'Project root is not writable; cannot create .env.');
        }

        return Check::ok('env-target', '.env will be created from .env.example.');
    }

    /**
     * `.env.example` ships in production mode; a public BASE_URL still running in development
     * (debug output, API docs, no HTTPS enforcement) is the silent mistake this warns about.
     * Local hosts are fine in any mode. Null when there is no .env to read yet.
     */
    private function environmentCheck(): ?Check
    {
        $env = $this->basePath . '/.env';
        if (!is_file($env)) {
            return null;
        }

        $writer = new EnvWriter($env);
        $appEnv = (string) ($writer->get('APP_ENV') ?? 'production');
        $host = (string) (parse_url((string) ($writer->get('BASE_URL') ?? ''), PHP_URL_HOST) ?? '');

        if ($appEnv === 'production') {
            return Check::ok('environment', 'Production mode.');
        }
        if ($host === '' || $this->isLocalHost($host)) {
            return Check::ok('environment', "{$appEnv} mode with a local BASE_URL.");
        }

        return Check::warn(
            'environment',
            "APP_ENV={$appEnv} but BASE_URL points at a public host ({$host}) — set APP_ENV=production"
            . ' (php glueful system:production) before going live.',
        );
    }

    /**
     * `/theme-assets/*` and `/_thallo/*` are served by PHP, not from disk. A web-server rule that
     * serves every `.css`/`.js`/`.woff2` URL straight from the document root (CloudPanel's and many
     * nginx templates) answers them with 404 and the rendered site and the designer load unstyled.
     * Probe one theme asset on the public BASE_URL; a 404 is that misconfiguration. Null when
     * there is no public BASE_URL to probe or the host is unreachable from here (no verdict).
     */
    private function assetRoutingCheck(): ?Check
    {
        $env = $this->basePath . '/.env';
        if (!is_file($env)) {
            return null;
        }
        $base = rtrim((string) ((new EnvWriter($env))->get('BASE_URL') ?? ''), '/');
        $host = (string) (parse_url($base, PHP_URL_HOST) ?? '');
        if ($host === '' || $this->isLocalHost($host)) {
            return null;
        }

        $url = $base . '/theme-assets/site.css?t=default';
        $status = ($this->httpProbe ?? self::defaultHttpProbe(...))($url);
        if ($status === null) {
            return null;
        }
        if ($status === 404) {
            return Check::warn(
                'asset-routing',
                "{$base}/theme-assets/site.css answers 404 — the web server is serving /theme-assets/* and "
                . '/_thallo/* from disk instead of passing them to PHP; the site and the designer will load '
                . 'unstyled. Add the location rule for those two prefixes above the static-file rule '
                . '(docs/production.md, "PHP-served asset paths (web server)").',
            );
        }

        return Check::ok('asset-routing', 'PHP-served asset paths reach PHP.');
    }

    private static function defaultHttpProbe(string $url): ?int
    {
        $context = stream_context_create([
            'http' => ['method' => 'GET', 'timeout' => 4, 'ignore_errors' => true, 'follow_location' => 1],
            'ssl' => ['verify_peer' => true],
        ]);
        $headers = @get_headers($url, true, $context);
        if ($headers === false) {
            return null;
        }
        // With redirects followed, get_headers() reports every status line; the last one is final.
        $status = null;
        foreach ($headers as $key => $value) {
            if (is_int($key) && is_string($value) && preg_match('#^HTTP/\S+\s+(\d{3})#', $value, $m) === 1) {
                $status = (int) $m[1];
            }
        }
        return $status;
    }

    private function isLocalHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));

        return in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true)
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.test');
    }

    private function writableStorageCheck(): Check
    {
        $storage = $this->basePath . '/storage';
        return is_dir($storage) && is_writable($storage)
            ? Check::ok('storage', 'storage/ is writable.')
            : Check::fail('storage', 'storage/ is missing or not writable.');
    }

    /**
     * Security keys are a WARNING when absent, never a hard preflight FAIL — on a fresh checkout
     * they are absent BY DESIGN and `thallo:setup` generates them via the Installer. Standalone
     * `thallo:doctor --strict` promotes this WARN to a failure (a configured-but-keyless install is
     * a real post-setup problem). All three framework keys are checked (TOKEN_SALT included).
     */
    private function keysCheck(): Check
    {
        $env = $this->basePath . '/.env';
        if (!is_file($env)) {
            return Check::warn('keys', 'No .env yet — security keys will be generated by setup.');
        }

        $writer = new EnvWriter($env);
        $missing = [];
        foreach (['APP_KEY', 'TOKEN_SALT', 'JWT_KEY'] as $key) {
            if ((string) ($writer->get($key) ?? '') === '') {
                $missing[] = $key;
            }
        }

        return $missing === []
            ? Check::ok('keys', 'Security keys present.')
            : Check::warn('keys', 'Missing security keys: ' . implode(', ', $missing) . '.');
    }
}
