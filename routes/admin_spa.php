<?php

declare(strict_types=1);

use Thallo\Core\Http\Controllers\AdminConfigController;
use Thallo\Core\Http\Controllers\SetupController;
use Glueful\Routing\Router;

/** @var Router $router */

/*
 * Admin runtime config — UNAUTHENTICATED by design, fetched by the SPA at boot (before login) to
 * learn the API base and whether first-run setup has run. It is a DYNAMIC endpoint, not a file —
 * named `/admin/config` (no `.json`) precisely so it can never collide with a static asset the way
 * the old `/admin/config.json` did (Apache serves a same-named file directly, shadowing the route).
 *
 * Auto-discovered by RouteManifest; the provider must NOT loadRoutesFrom() this file (it would
 * double-register). The compiled bundle itself (/admin + /admin/{rest}) is NOT served here — it is
 * mounted by the framework's serveFrontend() seam in CoreServiceProvider::boot(). This is a STATIC
 * route, so the router's O(1) static-first lookup always matches it before serveFrontend's dynamic
 * /admin/{rest} catch-all — it is never swallowed by the SPA fallback.
 *
 * This route is NOT under /v1/admin and carries no `auth` middleware; every API call the SPA
 * makes IS auth-gated under /v1/admin.
 */
$router->get('/admin/config', [AdminConfigController::class, 'config'])
    ->middleware('tenant_system');

/*
 * First-run setup — UNAUTHENTICATED but self-locking: SetupController returns 409 once installed.
 * Outside /v1/admin (no admin exists yet to auth against). Like /admin/config, this is a static
 * route, so the router's static-first lookup matches it before serveFrontend's /admin/{rest}
 * catch-all — never swallowed by the SPA fallback.
 */
$router->post('/admin/setup', [SetupController::class, 'setup'])
    ->middleware('tenant_system');
