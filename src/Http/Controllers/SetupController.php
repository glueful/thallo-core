<?php

declare(strict_types=1);

namespace Thallo\Core\Http\Controllers;

use Thallo\Core\Content\Http\DTOs\Requests\SetupData;
use Thallo\Core\Setup\SetupService;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Http\Response;
use Glueful\Installer\EnvWriter;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * First-run web setup — UNAUTHENTICATED by design (there is no admin yet to authenticate), but
 * SELF-LOCKING: once SetupService::isInstalled() is true it returns 409 forever, so a second
 * "first" admin can never be created. The heavy lifting (and the race-safety) lives in
 * SetupService::install(), which the future `php glueful thallo:setup` CLI shares.
 *
 * Responses use the framework's standard envelope via Glueful\Http\Response (success / error),
 * matching the rest of the API.
 *
 * On a successful install it also records the request's origin as BASE_URL in .env (silent
 * overwrite) so the install's canonical URL is captured without manual entry. This is HTTP-only
 * (the CLI install path has no request); see persistBaseUrl().
 */
final class SetupController
{
    /**
     * Query parameter the provision-printed setup link carries the token in
     * (`/admin/setup?st=…`). The admin reads it once, drops it from the address bar and sends it
     * back as X-Setup-Token. Short on purpose; the secret is the value, not the name.
     */
    public const TOKEN_QUERY_PARAM = 'st';

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly SetupService $setup,
    ) {
    }

    #[ApiOperation(
        summary: 'First-run web setup',
        description: 'Unauthenticated, self-locking first-run setup: creates the first admin and '
            . 'writes site settings. Returns 409 forever once the instance is installed — a second '
            . '"first" admin can never be created.',
        tags: ['Thallo Setup'],
    )]
    #[ApiResponse(200, description: 'Setup complete; the first admin was created.')]
    #[ApiResponse(409, description: 'Already installed — setup is permanently locked.')]
    #[ApiResponse(422, description: 'Invalid setup payload (site name, admin email/password, locale).')]
    public function setup(SetupData $input, ?Request $request = null): Response
    {
        // Permanent lock: refuse once installed. This is the gate; install() ALSO re-checks
        // inside its transaction, so even a TOCTOU race past this point cannot double-create.
        if ($this->setup->isInstalled()) {
            return Response::error('Setup has already been completed.', 409);
        }

        // Guard the unauthenticated first-run endpoint so a random first caller can't claim the
        // instance on a public deploy (see config thallo.setup.token).
        $denied = $this->assertSetupAllowed($request);
        if ($denied !== null) {
            return $denied;
        }

        try {
            $this->setup->install(
                $input->site_name,
                $input->admin_email,
                $input->admin_password,
                $input->locale,
                $input->admin_url,
            );
        } catch (\RuntimeException) {
            return Response::error('Setup has already been completed.', 409);
        }

        // Record the URL this instance was set up on as its canonical BASE_URL (used for absolute
        // links: docs server, CDN, emails, signed URLs). Setup is operator-initiated on the real
        // host, so deriving it from the request here is safe (unlike per-request URL generation).
        // The router always injects $request over HTTP; it's null only for direct/CLI invocation
        // (no request to derive from), which skips the write.
        if ($request !== null) {
            $this->persistBaseUrl($request->getSchemeAndHttpHost());
        }

        // The setup link is single-use: the endpoint now locks itself (409), so the token has no
        // further purpose and must not linger in .env.
        self::clearSetupToken(base_path($this->context, '.env'));

        return Response::success(['installed' => true], 'Setup complete.');
    }

    /** Blank SETUP_TOKEN after a completed setup; best-effort, never fails the setup itself. */
    public static function clearSetupToken(string $envPath): void
    {
        try {
            if (is_file($envPath) && ((new EnvWriter($envPath))->get('SETUP_TOKEN') ?? '') !== '') {
                (new EnvWriter($envPath))->set('SETUP_TOKEN', '');
            }
        } catch (\Throwable $e) {
            error_log('Setup: failed to clear SETUP_TOKEN: ' . $e->getMessage());
        }
    }

    /**
     * Authorize a first-run setup attempt. Returns a 403 Response when denied, or null when allowed.
     *
     *   - CLI/direct invocation ($request === null) is trusted — always allowed.
     *   - When a setup token is configured, the request MUST present it in the X-Setup-Token header
     *     (constant-time compare); a missing/wrong token is refused.
     *   - With no token configured, setup is allowed only outside production; in production it is
     *     refused so the operator must set SETUP_TOKEN (or use the CLI) to provision.
     */
    private function assertSetupAllowed(?Request $request): ?Response
    {
        if ($request === null) {
            return null; // CLI/trusted path — no HTTP caller to gate.
        }

        $expected = (string) config($this->context, 'thallo.setup.token', '');
        if ($expected !== '') {
            $provided = (string) ($request->headers->get('X-Setup-Token') ?? '');
            if (!hash_equals($expected, $provided)) {
                return Response::error(
                    'Invalid or missing setup token. Open the setup link printed by '
                    . '`php glueful thallo:provision` (run it again to print the link).',
                    403,
                );
            }
            return null;
        }

        if (env('APP_ENV') === 'production') {
            return Response::error(
                'First-run setup needs the setup link printed by `php glueful thallo:provision` '
                . '(run it again to print the link), or create the admin with `thallo:create-admin`.',
                403,
            );
        }

        return null; // no token configured, non-production — zero-config local setup stays open.
    }

    /**
     * Write the detected origin to BASE_URL in .env (silent overwrite). Non-fatal: setup has already
     * succeeded, so a failed write (e.g. read-only .env) only means the operator sets it by hand.
     */
    private function persistBaseUrl(string $baseUrl): void
    {
        try {
            (new EnvWriter(base_path($this->context, '.env')))->set('BASE_URL', $baseUrl);
        } catch (\Throwable $e) {
            error_log('Setup: failed to persist BASE_URL: ' . $e->getMessage());
        }
    }
}
