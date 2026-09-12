<?php

declare(strict_types=1);

use Thallo\Core\Http\Controllers\FormSubmitController;
use Glueful\Routing\Router;

/** @var Router $router */

/*
 * Public form submission (form-block spec §7). Reserved '/_forms' prefix (like
 * '/_preview'). UNAUTHENTICATED by design — the sealed '_form' descriptor IS the
 * authorization and the schema. Because there is no user to key a limit on, the
 * endpoint is rate-limited by IP. POST only, so the render page cache never applies.
 *
 * Auto-discovered by RouteManifest; the provider must NOT loadRoutesFrom() this file.
 */
$router->post('/_forms/submit', [FormSubmitController::class, 'submit'])
    ->middleware('tenant_bootstrap')
    ->middleware('rate_limit')
    ->rateLimit(30, 1, by: 'ip');
