<?php

declare(strict_types=1);

use Thallo\Core\Content\Http\Controllers\PreviewController;
use Glueful\Routing\Router;

/** @var Router $router */

/*
 * The public preview door (V1_DESIGN §6). UNAUTHENTICATED by design — the signed token
 * IS the capability, so there is NO `auth` middleware here. Because there is no user to
 * key a limit on, the endpoint is rate-limited by IP (DoS / brute-force resistance).
 *
 * The token is `base64url(payload).base64url(sig)` — it contains a `.`, `-` and `_`. The
 * framework router's default param constraint is `[^/]+` (Route::compilePattern) matched
 * against the rawurldecode()'d path, so a dotted token rides safely in the `{token}` path
 * segment (no `/`, no percent-encoding involved). No `?t=` fallback needed.
 *
 * Auto-discovered by RouteManifest; the provider must NOT loadRoutesFrom() this file
 * (double registration throws on duplicate static routes).
 */
$router->group(
    ['prefix' => '/v1/preview', 'middleware' => ['tenant_profile:public', 'tenant_bootstrap']],
    function (Router $router): void {
        // Read a draft via a signed preview token (unauthenticated; rate-limited by IP).
        $router->get('/{token}', [PreviewController::class, 'show'])
            ->middleware('rate_limit')
            ->rateLimit(60, 1, by: 'ip');
    }
);
