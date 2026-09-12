<?php

declare(strict_types=1);

use Thallo\Core\Http\Controllers\SignupController;
use Glueful\Routing\Router;

/** @var Router $router */

$router->group(['prefix' => '/v1/signup'], function (Router $router): void {
    $router->post('/member', [SignupController::class, 'member'])
        ->middleware(['tenant_profile:public', 'tenant_bootstrap', 'rate_limit'])
        ->rateLimit(30, 1, by: 'ip');
    $router->post('/member/join', [SignupController::class, 'join'])
        ->middleware(['tenant_profile:public', 'tenant_bootstrap', 'auth', 'rate_limit'])
        ->rateLimit(30, 1, by: 'ip');

    $router->post('/workspace', [SignupController::class, 'workspace'])
        ->middleware(['tenant_system', 'rate_limit'])
        ->rateLimit(30, 1, by: 'ip');
    $router->post('/workspace/authenticated', [SignupController::class, 'workspaceAuthenticated'])
        ->middleware(['tenant_system', 'auth', 'rate_limit'])
        ->rateLimit(30, 1, by: 'ip');

    $router->post('/verify', [SignupController::class, 'verify'])
        ->middleware(['tenant_system', 'rate_limit'])
        ->rateLimit(60, 1, by: 'ip');
    $router->post('/continue', [SignupController::class, 'continue'])
        ->middleware(['tenant_system', 'rate_limit'])
        ->rateLimit(60, 1, by: 'ip');
    $router->post('/reverify', [SignupController::class, 'reverify'])
        ->middleware(['tenant_system', 'rate_limit'])
        ->rateLimit(30, 1, by: 'ip');
});
