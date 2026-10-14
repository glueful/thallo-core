<?php

declare(strict_types=1);

namespace Thallo\Core\Updater;

/**
 * The guard (spec §7, §16.3), included by public/index.php and glueful before bootstrap/app.php.
 * Plain PHP: it must work while vendor/ is being replaced. Guard routes (/_update…) never boot the
 * application and never take the barrier; everything else is admitted or refused here.
 *
 * It reads only what the runner publishes in the control folder (status, the closed flag, the
 * admission lock, read-only) and writes only recover.json in the inbox, which the runner validates.
 */
final class Guard
{
    /** @var resource|null held for the request's or process's life */
    private static $process = null;
    /** @var resource|null */
    private $held = null;
    private \Closure $sleep;

    public function __construct(
        private readonly Paths $paths,
        private readonly Barrier $barrier,
        ?\Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (): void {
            sleep(5);
        };
    }

    public static function boot(string $base): void
    {
        $paths = new Paths($base);
        $guard = new self($paths, new Barrier($paths));
        if (PHP_SAPI === 'cli') {
            $env = getenv();
            $code = $guard->console($_SERVER['argv'] ?? [], is_array($env) ? $env : []);
            if ($code !== null) {
                exit($code);
            }
        } else {
            $headers = [];
            foreach ($_SERVER as $k => $v) {
                if (is_string($v) && str_starts_with($k, 'HTTP_')) {
                    $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
                }
            }
            $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
            $r = $guard->web(
                (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
                is_string($path) ? $path : '/',
                $headers,
                (string) file_get_contents('php://input'),
            );
            if ($r !== null) {
                $r->send();
                exit;
            }
        }
        self::$process = $guard->held();
    }

    /** @param array<string,string> $headers */
    public function web(string $method, string $path, array $headers, string $body): ?GuardResponse
    {
        if ($path === '/_update' || str_starts_with($path, '/_update/')) {
            return $this->route($method, $path, $headers, $body);
        }
        if ($this->admitted()) {
            return null;
        }
        if ($path === '/health') {
            return GuardResponse::json(503, ['status' => 'updating']);
        }
        $headers = [
            'Content-Type' => 'text/html; charset=utf-8',
            'Retry-After' => '120',
            'Cache-Control' => 'no-store',
        ];
        return new GuardResponse(503, $headers, self::closedPage());
    }

    /** @param list<string> $argv @param array<string,string> $env */
    public function console(array $argv, array $env): ?int
    {
        if (($env['THALLO_UPDATE_CHILD'] ?? '') === '1') {
            return null;
        }
        $command = $argv[1] ?? '';
        if ($command === 'queue:work') {
            while (!$this->admitted()) {
                ($this->sleep)();
            }
            return null;
        }
        if ($this->admitted()) {
            return null;
        }
        if ($command === 'queue:scheduler') {
            return 0;
        }
        fwrite(STDERR, "Thallo is being updated, or an update needs attention: see /_update, or run\n"
            . "php bin/thallo-updater recover --rollback | --retry-provision | --reopen as the code owner.\n");
        return 1;
    }

    /** @return resource|null */
    public function held()
    {
        return $this->held;
    }

    /**
     * Admitted with the shared lock; or admitted without it when the lock file cannot be opened and
     * no update has closed the site — a misconfiguration (folder permissions), logged, never a
     * reason to show the maintenance page (spec §16.3).
     */
    private function admitted(): bool
    {
        $h = $this->barrier->admit();
        if (is_resource($h)) {
            $this->held = $h;
            return true;
        }
        if ($h === false && !$this->barrier->isClosed()) {
            error_log('Thallo update guard: cannot open ' . $this->paths->admissionLock()
                . '; run php glueful thallo:provision as the code owner to set the update folders up.');
            return true;
        }
        return false;
    }

    /** @param array<string,string> $headers */
    private function route(string $method, string $path, array $headers, string $body): GuardResponse
    {
        if ($path === '/_update' && $method === 'GET') {
            $headers = ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store'];
            return new GuardResponse(200, $headers, RecoveryPage::html());
        }
        if ($path === '/_update/verify' && $method === 'POST') {
            return $this->verify($headers, $body);
        }
        $token = $headers['x-thallo-update-token'] ?? '';
        $status = AtomicFile::readJson($this->paths->status()) ?? [];
        $published = $token !== '' && hash_equals((string) ($status['token_hash'] ?? ''), hash('sha256', $token));
        if ($path === '/_update/status' && $method === 'GET') {
            if ($published) {
                return GuardResponse::json(200, [
                    'operation' => $status['operation'] ?? [],
                    'recovery_options' => $status['recovery_options'] ?? [],
                    'log' => (string) ($status['log'] ?? ''),
                ]);
            }
            // Filed but not yet claimed: the runner has not published it, the inbox still holds it.
            $pending = $this->pendingRequest();
            $filed = $pending !== null ? (string) ($pending['token_hash'] ?? '') : '';
            if ($token !== '' && $filed !== '' && hash_equals($filed, hash('sha256', $token))) {
                $operation = ['status' => Status::REQUESTED, 'target' => (string) ($pending['target'] ?? '')];
                return GuardResponse::json(200, ['operation' => $operation, 'recovery_options' => [], 'log' => '']);
            }
            return GuardResponse::json(403, ['error' => 'token']);
        }
        if ($path === '/_update/recover' && $method === 'POST') {
            if (!$published) {
                return GuardResponse::json(403, ['error' => 'token']);
            }
            $in = json_decode($body, true);
            $option = is_array($in) ? (string) ($in['option'] ?? '') : '';
            if ($option === 'reopen' && (!is_array($in) || ($in['confirm'] ?? '') !== 'REOPEN')) {
                return GuardResponse::json(422, ['error' => 'confirm']);
            }
            $offered = (array) ($status['recovery_options'] ?? []);
            $recovering = ($status['operation']['status'] ?? '') === Status::RECOVERY_REQUIRED;
            if (!$recovering || !in_array($option, $offered, true)) {
                return GuardResponse::json(409, ['error' => 'not_recoverable']);
            }
            // A request only: the runner checks the token and the option against its own records.
            AtomicFile::writeJson($this->paths->recoverRequest(), ['option' => $option, 'token' => $token], 0660);
            return GuardResponse::json(202, ['requested' => $option]);
        }
        return GuardResponse::json(404, ['error' => 'not_found']);
    }

    private function pendingRequest(): ?array
    {
        $file = $this->paths->startRequest();
        return is_file($file) && !is_link($file) && filesize($file) <= 4096 ? AtomicFile::readJson($file) : null;
    }

    /** This process's parent: posix where loaded, else Linux's /proc. */
    private static function parentPid(): ?int
    {
        if (function_exists('posix_getppid')) {
            return posix_getppid();
        }
        $stat = @file_get_contents('/proc/self/stat');
        return is_string($stat) && preg_match('/\)\s+\S+\s+(\d+)/', $stat, $m) ? (int) $m[1] : null;
    }

    /** @param array<string,string> $headers */
    private function verify(array $headers, string $body): GuardResponse
    {
        $file = $this->paths->verifySecret();
        $secret = is_file($file) ? trim((string) file_get_contents($file)) : '';
        $given = $headers['x-thallo-verify'] ?? '';
        if ($secret === '' || $given === '' || !hash_equals($secret, $given)) {
            return GuardResponse::json(403, ['error' => 'secret']);
        }
        $in = json_decode($body, true);
        $mode = is_array($in) ? ($in['mode'] ?? '') : '';
        if ($mode === 'probe') {
            return GuardResponse::json(200, [
                'sapi' => PHP_SAPI,
                // The master of the PHP-FPM pool answering: the runner counts only it among PHP-FPMs.
                'fpm_master' => PHP_SAPI === 'fpm-fcgi' ? self::parentPid() : null,
                'writable_code' => is_writable($this->paths->base . '/vendor')
                    || is_writable($this->paths->base . '/composer.lock'),
                'admission_ok' => (static function (string $lock): bool {
                    $h = FileLock::shared($lock);
                    if (is_resource($h)) {
                        FileLock::release($h);
                    }
                    return $h !== false; // opened (null: opened, held by the runner)
                })($this->paths->admissionLock()),
                'state_writable' => is_writable($this->paths->inbox()),
                // The boundary (spec §16.1): PHP-FPM must not be able to write what the runner trusts.
                'control_writable' => is_writable($this->paths->controlDir())
                    || is_writable($this->paths->admissionLock()),
                'private_readable' => @is_readable($this->paths->privateDir()),
                'opcache' => [
                    'enabled' => (bool) ini_get('opcache.enable'),
                    'validate_timestamps' => (bool) ini_get('opcache.validate_timestamps'),
                    'revalidate_freq' => (int) ini_get('opcache.revalidate_freq'),
                    'preload' => (string) ini_get('opcache.preload'),
                ],
            ]);
        }
        if ($mode !== 'boot') {
            return GuardResponse::json(422, ['error' => 'mode']);
        }
        register_shutdown_function(static function (): void {
            $e = error_get_last();
            $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
            if ($e !== null && in_array($e['type'], $fatal, true) && !headers_sent()) {
                GuardResponse::json(500, ['error' => $e['message']])->send();
            }
        });
        try {
            $app = require $this->paths->base . '/bootstrap/app.php';
            $missing = [];
            $core = $this->paths->base . '/vendor/glueful/thallo-core/composer.json';
            $require = is_file($core) ? (json_decode((string) file_get_contents($core), true)['require'] ?? []) : [];
            foreach (array_keys($require) as $name) {
                if (str_starts_with($name, 'ext-') && !extension_loaded(substr($name, 4))) {
                    $missing[] = substr($name, 4);
                }
            }
            $version = class_exists('Composer\\InstalledVersions')
                ? \Composer\InstalledVersions::getPrettyVersion('glueful/thallo-core')
                : null;
            return GuardResponse::json(200, [
                'version' => $version,
                'php' => PHP_VERSION,
                'missing_extensions' => $missing,
                'container' => is_object($app) && method_exists($app, 'getContainer') && $app->getContainer() !== null,
            ]);
        } catch (\Throwable $e) {
            return GuardResponse::json(500, ['error' => $e->getMessage()]);
        }
    }

    private static function closedPage(): string
    {
        return '<!doctype html><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Back in a few minutes</title>'
            . '<body style="font:16px system-ui;display:grid;place-items:center;min-height:90vh;margin:0 16px">'
            . '<main style="max-width:32rem;text-align:center"><h1 style="font-size:1.4rem">Back in a few minutes</h1>'
            . '<p>This site is being updated. Please try again shortly.</p></main>';
    }
}
