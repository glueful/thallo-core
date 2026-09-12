<?php

declare(strict_types=1);

namespace Thallo\Core\Http\Controllers;

use Thallo\Core\Support\ReadmeRenderer;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Exceptions\LockContentionException;
use Glueful\Extensions\EnabledProviders;
use Glueful\Extensions\ExtensionManager;
use Glueful\Extensions\Install\ExtensionInstaller;
use Glueful\Extensions\Install\HostCapability;
use Glueful\Extensions\Install\HostNotWritableException;
use Glueful\Extensions\Install\InstallDisabledException;
use Glueful\Extensions\Install\PackageNotAllowedException;
use Glueful\Extensions\PackageManifest;
use Glueful\Extensions\ProtectedProviders;
use Glueful\Extensions\Schema\DescriptorInventory;
use Glueful\Extensions\Schema\ExtensionOperation;
use Glueful\Extensions\Schema\ExtensionSchemaExecutor;
use Glueful\Extensions\Schema\ReadinessState;
use Glueful\Extensions\Schema\SchemaNotBootstrappedException;
use Glueful\Extensions\Schema\SchemaReadiness;
use Glueful\Extensions\Schema\UndeclaredSchemaException;
use Glueful\Helpers\RequestHelper;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\Request;

/**
 * Admin API for the extensions screen.
 *
 *  - Installed: the locally-discovered `glueful-extension` packages (PackageManifest) plus the
 *    enabled allow-list (config/extensions.php) and each package's schema readiness. All local —
 *    no network.
 *  - Browse: proxies Packagist filtered to `type=glueful-extension` server-side, so the SPA
 *    avoids CORS / rate limits and we can mark which results are already installed.
 *  - Enable/disable: drives the shared ExtensionSchemaExecutor (schema policy spec B5) —
 *    migrate-first, lock-serialized, truthful persisted operation record. Works in production;
 *    the host-writability precondition (immutable deploys) refuses with 409 instead.
 *
 * Gated by `system.access` (see routes/admin.php).
 */
class ExtensionAdminController
{
    private const PACKAGIST_SEARCH = 'https://packagist.org/search.json';

    /** Render at most the first 512 KiB of a README — a hard ceiling on unbounded files. */
    private const README_MAX_BYTES = 512 * 1024;

    /** README filenames to look for, in preference order. */
    private const README_NAMES = ['README.md', 'README.markdown', 'readme.md', 'Readme.md', 'README'];

    public function __construct(private readonly ApplicationContext $context)
    {
    }

    /** GET /v1/admin/extensions — installed glueful extensions with their enabled state. */
    #[ApiOperation(
        summary: 'List installed extensions',
        description: 'Installed glueful-extension packages with version, provider, dependencies and '
            . 'enabled state. Requires the `system.access` permission.',
        tags: ['Extensions'],
    )]
    #[ApiResponse(200, description: 'Installed extensions.')]
    #[ApiResponse(401, description: 'Authentication required')]
    #[ApiResponse(403, description: 'Missing the system.access permission')]
    public function index(): Response
    {
        return Response::success(['extensions' => $this->installed()], 'Installed extensions.');
    }

    /** GET /v1/admin/extensions/registry — browse the Packagist glueful-extension catalog. */
    #[ApiOperation(
        summary: 'Browse the extension catalog',
        description: 'Searches Packagist for `type=glueful-extension` packages (optional `q` filter) '
            . 'and flags those already installed. Requires the `system.access` permission.',
        tags: ['Extensions'],
    )]
    #[ApiResponse(200, description: 'Catalog results, each with an `installed` flag.')]
    public function registry(Request $request): Response
    {
        $query = trim((string) $request->query->get('q', ''));
        // name => enabled, so a Browse card for an installed package can show a live
        // Enable/Disable toggle instead of a dead "Installed" badge.
        $enabledByName = [];
        foreach ($this->installed() as $e) {
            $enabledByName[(string) $e['name']] = (bool) ($e['enabled'] ?? false);
        }

        $params = ['type' => 'glueful-extension', 'per_page' => 30];
        if ($query !== '') {
            $params['q'] = $query;
        }
        $url = self::PACKAGIST_SEARCH . '?' . http_build_query($params);

        try {
            $body = HttpClient::create(['timeout' => 8])->request('GET', $url)->toArray(false);
        } catch (\Throwable) {
            return Response::success(
                ['results' => [], 'available' => false],
                'The extension catalog is currently unavailable.',
            );
        }

        $results = [];
        foreach ((is_array($body['results'] ?? null) ? $body['results'] : []) as $pkg) {
            if (!is_array($pkg) || !is_string($pkg['name'] ?? null)) {
                continue;
            }
            $results[] = [
                'name' => $pkg['name'],
                'description' => is_string($pkg['description'] ?? null) ? $pkg['description'] : null,
                'url' => is_string($pkg['url'] ?? null) ? $pkg['url'] : null,
                'repository' => is_string($pkg['repository'] ?? null) ? $pkg['repository'] : null,
                'downloads' => (int) ($pkg['downloads'] ?? 0),
                'favers' => (int) ($pkg['favers'] ?? 0),
                'installed' => array_key_exists($pkg['name'], $enabledByName),
                'enabled' => $enabledByName[$pkg['name']] ?? false,
            ];
        }

        return Response::success(['results' => $results, 'available' => true], 'Catalog retrieved.');
    }

    /** POST /v1/admin/extensions/enable — activate an installed extension through the executor. */
    #[ApiOperation(
        summary: 'Enable an installed extension',
        description: 'Migrates the extension schema first, then enables it — the shared schema '
            . 'executor serializes the whole operation and records its truthful outcome. Requires '
            . 'the `system.access` permission.',
        tags: ['Extensions'],
    )]
    #[ApiResponse(200, description: 'Extension enabled (a stale provider cache carries a warning).')]
    #[ApiResponse(409, description: 'Refused (protected/unwritable/lock) or the operation failed.')]
    public function enable(Request $request): Response
    {
        return $this->toggle($request, true);
    }

    /** POST /v1/admin/extensions/disable — deactivate an installed extension through the executor. */
    #[ApiOperation(
        summary: 'Disable an installed extension',
        description: 'Disables the extension through the shared schema executor (never any schema '
            . 'change — tables and data are preserved). Requires the `system.access` permission.',
        tags: ['Extensions'],
    )]
    #[ApiResponse(200, description: 'Extension disabled.')]
    #[ApiResponse(409, description: 'Refused (protected/unwritable/lock) or the operation failed.')]
    public function disable(Request $request): Response
    {
        return $this->toggle($request, false);
    }

    /** POST /v1/admin/extensions/install — composer require a new glueful extension (dev only). */
    #[ApiOperation(
        summary: 'Install a glueful extension',
        description: 'Runs `composer require` for a catalog extension SYNCHRONOUSLY (the request '
            . 'blocks until composer finishes). On success the extension is installed but DISABLED '
            . '— enable it with the toggle. Dev only (composer cannot write on immutable production '
            . 'hosts). Requires the `system.access` permission.',
        tags: ['Extensions'],
    )]
    #[ApiResponse(200, description: 'Installed — enable it to activate.')]
    #[ApiResponse(403, description: 'Installer disabled (production/kill-switch).')]
    #[ApiResponse(409, description: 'Host filesystem is not writable (immutable deploy).')]
    #[ApiResponse(422, description: 'Not an installable glueful extension, or composer failed.')]
    public function install(Request $request): Response
    {
        $raw = RequestHelper::getRequestData($request)['name'] ?? null;
        $name = is_string($raw) ? trim($raw) : '';

        try {
            $result = app($this->context, ExtensionInstaller::class)->install($name);
        } catch (InstallDisabledException $e) {
            return Response::forbidden($e->getMessage());
        } catch (HostNotWritableException $e) {
            return Response::error($e->getMessage(), 409, ['reason' => $e->reason]);
        } catch (PackageNotAllowedException $e) {
            return Response::error($e->getMessage(), 422);
        }

        if (($result['status'] ?? null) !== 'installed') {
            return Response::error(
                is_string($result['error'] ?? null) ? $result['error'] : 'Install failed.',
                422,
                ['output' => is_string($result['output'] ?? null) ? $result['output'] : ''],
            );
        }

        return Response::success($result, 'Extension installed — enable it to activate.');
    }

    /** GET /v1/admin/extensions/{vendor}/{name}/readme — rendered README for an installed extension. */
    #[ApiOperation(
        summary: 'Render an installed extension README',
        description: 'Renders the README of an installed glueful-extension package to safe HTML '
            . '(CommonMark, raw HTML escaped, unsafe links blocked, images stripped). The package '
            . 'path is resolved through the installed-extension registry, never from the request. '
            . 'Cacheable via ETag. Requires the `system.access` permission.',
        tags: ['Extensions'],
    )]
    #[ApiResponse(200, description: 'Rendered README (or found=false when the package ships none).')]
    #[ApiResponse(304, description: 'Not modified (ETag matched).')]
    #[ApiResponse(404, description: 'No such installed extension.')]
    public function readme(Request $request, string $vendor, string $name): Response
    {
        $package = $vendor . '/' . $name;

        // Source of truth: only INSTALLED glueful-extension packages. The vendor/name from the URL
        // are used to look the package up in the registry — never concatenated into a path — and the
        // install directory comes from Composer. No request value reaches the filesystem path, so
        // there is no traversal surface.
        if (!isset((new PackageManifest($this->context))->getCandidates()[$package])) {
            return Response::notFound("No installed extension named “{$package}”.");
        }

        $installPath = \Composer\InstalledVersions::getInstallPath($package);
        if (!is_string($installPath) || !is_dir($installPath)) {
            return Response::notFound("No installed extension named “{$package}”.");
        }

        [$file, $source] = $this->locateReadme($installPath);
        if ($file === null || $source === null) {
            return Response::success(['found' => false, 'html' => null, 'source' => null], 'No README.');
        }

        // README content is static until the package is reinstalled/updated, so an mtime+size ETag
        // lets the browser revalidate cheaply (and we skip rendering on a hit).
        $fingerprint = sha1($source . '|' . (string) filemtime($file) . '|' . (string) filesize($file));
        $etag = '"' . substr($fingerprint, 0, 20) . '"';
        $ifNoneMatch = $request->headers->get('If-None-Match');
        $notModified = is_string($ifNoneMatch) && trim($ifNoneMatch) === $etag;

        if ($notModified) {
            $response = Response::success(['found' => true, 'source' => $source], 'Not modified.');
        } else {
            $markdown = (string) file_get_contents($file, false, null, 0, self::README_MAX_BYTES);
            $html = (new ReadmeRenderer($request->getHost()))->render($markdown);
            $response = Response::success(['found' => true, 'html' => $html, 'source' => $source], 'README rendered.');
        }

        $response->setEtag($etag);
        $response->headers->set('Cache-Control', 'private, must-revalidate');
        if ($notModified) {
            $response->setNotModified();
        }

        return $response;
    }

    /**
     * Locate a README within an extension's install directory.
     *
     * @return array{0:?string,1:?string} [absolute path, filename] or [null, null]
     */
    private function locateReadme(string $installPath): array
    {
        foreach (self::README_NAMES as $candidate) {
            $path = $installPath . DIRECTORY_SEPARATOR . $candidate;
            if (is_file($path)) {
                return [$path, $candidate];
            }
        }

        return [null, null];
    }

    private function toggle(Request $request, bool $enable): Response
    {
        // JSON body with form-encoded fallback: Symfony leaves $request->request empty for
        // an application/json body, so reading it alone would yield ''.
        $raw = RequestHelper::getRequestData($request)['name'] ?? null;
        $name = is_string($raw) ? trim($raw) : '';
        $candidate = (new PackageManifest($this->context))->getCandidates()[$name] ?? null;
        if ($candidate === null) {
            return Response::notFound("No installed extension named “{$name}”.");
        }

        // Protected providers refuse before writability: their activation is owned by a
        // lifecycle flow (extensions.protected), and no other precondition may mask that answer.
        if (($refusal = ProtectedProviders::refusalFor($this->context, $candidate->provider)) !== null) {
            return Response::error($refusal, 409);
        }

        // Host writability BEFORE the executor: an immutable deploy (read-only config or cache
        // dir) must refuse up front rather than fail halfway through a migrate-then-write.
        if (($cap = $this->hostToggleRefusal()) !== null) {
            return Response::error('Host not writable', 409, ['reason' => $cap['reason']]);
        }

        // Everything else — dependency dry-resolve, source locks, migrate-first/enable-last,
        // cache recompile, the persisted operation record — is the executor's (spec B5). This
        // surface keeps only HTTP concerns: authority, protected refusal, host writability.
        try {
            $executor = $this->schemaExecutor();
            $operation = $enable
                ? $executor->enable($name, 'admin-api')
                : $executor->disable($name, 'admin-api');
        } catch (SchemaNotBootstrappedException | UndeclaredSchemaException | LockContentionException $e) {
            return Response::error($e->getMessage(), 409);
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), 422);
        }

        $succeeded = in_array($operation->status, [
            ExtensionOperation::STATUS_SUCCEEDED,
            ExtensionOperation::STATUS_CACHE_STALE,
        ], true);
        $this->audit($enable ? 'extension.enable' : 'extension.disable', $name, $operation);

        $payload = [
            'name' => $name,
            'enabled' => $enable && $succeeded,
            'operation' => [
                'id' => $operation->id,
                'status' => $operation->status,
                'failed_migration' => $operation->failedMigration,
                'error' => $operation->error,
            ],
        ];
        if (!$succeeded) {
            return Response::error('Extension operation did not complete', 409, $payload);
        }
        $message = $operation->status === ExtensionOperation::STATUS_CACHE_STALE
            ? ($operation->error ?? 'State written, but the provider cache recompile failed — '
                . "re-run 'php glueful extensions:cache'.")
            : ($enable ? 'Extension enabled.' : 'Extension disabled.');
        return Response::success($payload, $message);
    }

    /** Overridable seam: the executor comes from the app container in production. */
    protected function schemaExecutor(): ExtensionSchemaExecutor
    {
        return app($this->context, ExtensionSchemaExecutor::class);
    }

    /**
     * Overridable seam: host writability (immutable-deploy detection).
     *
     * @return array{reason: string, detail: string}|null null = toggleable
     */
    protected function hostToggleRefusal(): ?array
    {
        return app($this->context, HostCapability::class)->forToggle();
    }

    /** Structured audit trail for the toggle: the persisted operation id and terminal status. */
    private function audit(string $action, string $package, ExtensionOperation $operation): void
    {
        try {
            app($this->context, LoggerInterface::class)->info($action, [
                'package' => $package,
                'operation_id' => $operation->id,
                'status' => $operation->status,
            ]);
        } catch (\Throwable) {
            // The audit line must never turn a completed operation into an HTTP failure.
        }
    }

    /**
     * Installed glueful-extension packages joined with the enabled allow-list.
     *
     * @return list<array<string,mixed>>
     */
    private function installed(): array
    {
        $candidates = (new PackageManifest($this->context))->getCandidates();
        $enabled = array_fill_keys(EnabledProviders::from($this->context), true);
        $meta = app($this->context, ExtensionManager::class)->listMeta();
        $info = $this->composerInfo();

        $out = [];
        foreach ($candidates as $name => $candidate) {
            $m = is_array($meta[$candidate->provider] ?? null) ? $meta[$candidate->provider] : [];
            $ci = $info[(string) $name] ?? ['description' => null, 'author' => null];
            // Prefer Composer's canonical description (present for every package, and the same
            // text the Browse tab shows from Packagist); fall back to a registerMeta() override.
            $description = $ci['description']
                ?? (is_string($m['description'] ?? null) ? $m['description'] : null);
            $isEnabled = isset($enabled[$candidate->provider]);
            [$schemaState, $schemaReasons] = $this->schemaState((string) $name);
            $out[] = [
                'name' => (string) $name,
                'provider' => $candidate->provider,
                'version' => $candidate->version,
                'description' => $description,
                'author' => $ci['author'],
                'requires_extensions' => $candidate->requiresExtensions,
                'enabled' => $isEnabled,
                'schema_state' => $schemaState,
                'schema_reasons' => $schemaReasons,
                'cli_command' => $this->cliCommand($schemaState, (string) $name, $isEnabled),
            ];
        }

        return $out;
    }

    /** Overridable seam: is the package's migration manifest declared at all? */
    protected function packageIsDeclared(string $package): bool
    {
        return app($this->context, DescriptorInventory::class)->isDeclared($package);
    }

    /**
     * Overridable seam: raw per-source readiness (SchemaReadiness::forPackage).
     *
     * @return array<string, array{state: ReadinessState, reasons: list<string>}>
     */
    protected function packageReadiness(string $package): array
    {
        return app($this->context, SchemaReadiness::class)->forPackage($package);
    }

    /**
     * Closed schema-state aggregation for one installed package (schema policy spec B6):
     * `undeclared` (no extra.glueful.migrations), `none` (explicit "none"), otherwise
     * `divergent` if ANY descriptor is divergent, else `pending` if any is pending, else
     * `ready`. An undeclared third-party package stays listable — it can never make the whole
     * endpoint throw.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function schemaState(string $package): array
    {
        try {
            if (!$this->packageIsDeclared($package)) {
                return ['undeclared', [
                    "{$package} declares no extra.glueful.migrations manifest — its author should "
                    . 'declare migration descriptors or "migrations": "none".',
                ]];
            }
            $results = $this->packageReadiness($package);
        } catch (\Throwable $e) {
            // Fail-soft per row: a single package's schema question must never 500 the list.
            return ['undeclared', [$e->getMessage()]];
        }
        if ($results === []) {
            return ['none', []];
        }
        $divergent = [];
        $pending = [];
        foreach ($results as $source => $result) {
            $reasons = array_map(static fn(string $r): string => "{$source}: {$r}", $result['reasons']);
            if ($result['state'] === ReadinessState::Divergent) {
                $divergent = [...$divergent, ...$reasons];
            } elseif ($result['state'] === ReadinessState::Pending) {
                $pending = [...$pending, ...$reasons];
            }
        }
        if ($divergent !== []) {
            return ['divergent', $divergent];
        }
        if ($pending !== []) {
            return ['pending', $pending];
        }
        return ['ready', []];
    }

    /** The CLI equivalent an operator can run for this row's state. */
    private function cliCommand(string $schemaState, string $package, bool $enabled): string
    {
        if ($schemaState === 'divergent') {
            return 'php glueful migrate:verify';
        }
        return $enabled
            ? "php glueful extensions:disable {$package}"
            : "php glueful extensions:enable {$package}";
    }

    /**
     * Package name => {description, author}, read from Composer's installed.json.
     *
     * The framework's listMeta() registry only carries metadata when an extension opts in via
     * registerMeta(), so most installed extensions have none there; Composer's manifest always does.
     * The author is the first entry of the package's `authors` array.
     *
     * @return array<string, array{description:?string, author:?string}>
     */
    private function composerInfo(): array
    {
        try {
            $composerDir = dirname((new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName());
            $file = $composerDir . '/installed.json';
            if (!is_file($file)) {
                return [];
            }
            $data = json_decode((string) file_get_contents($file), true);
            $packages = is_array($data['packages'] ?? null) ? $data['packages'] : [];

            $out = [];
            foreach ($packages as $pkg) {
                if (!is_array($pkg) || !is_string($pkg['name'] ?? null)) {
                    continue;
                }
                $author = null;
                foreach ((is_array($pkg['authors'] ?? null) ? $pkg['authors'] : []) as $a) {
                    if (is_array($a) && is_string($a['name'] ?? null) && $a['name'] !== '') {
                        $author = $a['name'];
                        break;
                    }
                }
                $out[$pkg['name']] = [
                    'description' => is_string($pkg['description'] ?? null) ? $pkg['description'] : null,
                    'author' => $author,
                ];
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }
}
