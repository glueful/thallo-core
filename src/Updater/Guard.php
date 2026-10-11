<?php

declare(strict_types=1);

namespace Thallo\Core\Updater;

/**
 * The guard (spec §7), included by public/index.php and glueful before bootstrap/app.php. Plain
 * PHP: it must work while vendor/ is being replaced. Guard routes (/_update…) never boot the
 * application and never take the barrier; everything else is admitted or refused here.
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
        private readonly OperationStore $ops,
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
        $guard = new self($paths, new OperationStore($paths, static fn (): int => time()), new Barrier($paths));
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
        $this->held = $this->barrier->admit();
        if ($this->held !== null) {
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
            while (($this->held = $this->barrier->admit()) === null) {
                ($this->sleep)();
            }
            return null;
        }
        $this->held = $this->barrier->admit();
        if ($this->held !== null) {
            return null;
        }
        if ($command === 'queue:scheduler') {
            return 0;
        }
        fwrite(STDERR, "Thallo is being updated; try again when it finishes (see /_update).\n");
        return 1;
    }

    /** @return resource|null */
    public function held()
    {
        return $this->held;
    }

    /** @param array<string,string> $headers */
    private function route(string $method, string $path, array $headers, string $body): GuardResponse
    {
        return GuardResponse::json(404, ['error' => 'not_found']); // Tasks 5 and 6 add the routes
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
