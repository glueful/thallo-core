<?php

declare(strict_types=1);

namespace Thallo\Core\Http\Controllers;

use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Http\DTOs\UpdateAdminUiSettingsData;
use Thallo\Core\Settings\AdminUiSettings;
use Thallo\Core\Support\ActorHelper;

/**
 * A role's or a user's admin menus and landing page (Users & Access). Tidying only: hiding a menu
 * takes no right away — a permission does that. The signed-in user reads what applies to them in
 * `GET /account` (`ui`).
 */
final class AdminUiSettingsController
{
    private const MAX_PATH = 255;
    /** Pages signing in must never land on: they would sign the user out or loop. */
    private const NOT_LANDINGS = [
        '/login', '/logout', '/signup', '/setup', '/forgot-password', '/reset-password', '/verify-otp',
    ];

    public function __construct(private readonly AdminUiSettings $settings)
    {
    }

    /** A role's or user's settings (`roles`|`users`). */
    public function show(string $type, string $uuid): Response
    {
        $subject = $this->subject($type, $uuid);
        if ($subject === null) {
            return Response::notFound('No such ' . rtrim($type, 's') . '.');
        }
        return Response::success($this->settings->get($subject, $uuid));
    }

    /** Replace a role's or user's settings (`roles`|`users`). */
    public function update(UpdateAdminUiSettingsData $input, string $type, string $uuid, Request $request): Response
    {
        $subject = $this->subject($type, $uuid);
        if ($subject === null) {
            return Response::notFound('No such ' . rtrim($type, 's') . '.');
        }
        $errors = [];
        $allowed = $subject === AdminUiSettings::USER
            ? [AdminUiSettings::HIDDEN, AdminUiSettings::SHOWN]
            : [AdminUiSettings::HIDDEN];
        $menus = [];
        foreach ($input->menus as $path => $state) {
            $path = (string) $path;
            if (!self::isAdminPath($path)) {
                $errors["menus.{$path}"] = 'must be an admin path';
            } elseif (!in_array($state, $allowed, true)) {
                $errors["menus.{$path}"] = 'must be ' . implode(' or ', $allowed);
            } else {
                $menus[$path] = $state;
            }
        }
        $landing = $input->landing === null || trim($input->landing) === '' ? null : trim($input->landing);
        if ($landing !== null && (!self::isAdminPath($landing) || in_array($landing, self::NOT_LANDINGS, true))) {
            $errors['landing'] = 'must be an admin page';
        }
        if ($errors !== []) {
            return Response::validation($errors);
        }
        ksort($menus);
        $this->settings->put($subject, $uuid, $menus, $landing, ActorHelper::uuidFromRequest($request));
        return Response::success($this->settings->get($subject, $uuid), 'Saved.');
    }

    // One route per kind, so each carries its own permission (see core/routes/admin.php).
    /** GET /v1/admin/ui-settings/roles/{uuid} */
    #[ApiOperation(
        summary: 'A role\'s admin menus and landing page',
        description: '`menus` maps a sidebar item\'s path to `hidden` (or, for a user, `shown`, which '
            . 'beats their roles); `landing` is where signing in takes them, null for Home. Roles need '
            . '`users.roles.manage`, users `users.edit`.',
        tags: ['Thallo Admin'],
    )]
    #[ApiResponse(200, description: 'The settings; empty when nothing is set.')]
    #[ApiResponse(404, description: 'No such role or user.')]
    public function showRole(string $uuid): Response
    {
        return $this->show('roles', $uuid);
    }

    /** PUT /v1/admin/ui-settings/roles/{uuid} */
    #[ApiOperation(
        summary: 'Set a role\'s admin menus and landing page',
        description: 'Replaces the settings. `menus`: sidebar item path (`/…`) => `hidden`, or for a '
            . 'user `shown`. `landing`: an admin path, or null for Home. Nothing set clears them. '
            . 'Hiding a menu takes no permission away. Roles need `users.roles.manage`, users '
            . '`users.edit`.',
        tags: ['Thallo Admin'],
    )]
    #[ApiResponse(200, description: 'Saved; the settings.')]
    #[ApiResponse(404, description: 'No such role or user.')]
    #[ApiResponse(422, description: 'A path that is not an admin path, or a state a role cannot have.')]
    public function updateRole(UpdateAdminUiSettingsData $input, string $uuid, Request $request): Response
    {
        return $this->update($input, 'roles', $uuid, $request);
    }

    /** GET /v1/admin/ui-settings/users/{uuid} */
    #[ApiOperation(
        summary: 'A user\'s admin menus and landing page',
        description: '`menus` maps a sidebar item\'s path to `hidden` (or, for a user, `shown`, which '
            . 'beats their roles); `landing` is where signing in takes them, null for Home. Roles need '
            . '`users.roles.manage`, users `users.edit`.',
        tags: ['Thallo Admin'],
    )]
    #[ApiResponse(200, description: 'The settings; empty when nothing is set.')]
    #[ApiResponse(404, description: 'No such role or user.')]
    public function showUser(string $uuid): Response
    {
        return $this->show('users', $uuid);
    }

    /** PUT /v1/admin/ui-settings/users/{uuid} */
    #[ApiOperation(
        summary: 'Set a user\'s admin menus and landing page',
        description: 'Replaces the settings. `menus`: sidebar item path (`/…`) => `hidden`, or for a '
            . 'user `shown`. `landing`: an admin path, or null for Home. Nothing set clears them. '
            . 'Hiding a menu takes no permission away. Roles need `users.roles.manage`, users '
            . '`users.edit`.',
        tags: ['Thallo Admin'],
    )]
    #[ApiResponse(200, description: 'Saved; the settings.')]
    #[ApiResponse(404, description: 'No such role or user.')]
    #[ApiResponse(422, description: 'A path that is not an admin path, or a state a role cannot have.')]
    public function updateUser(UpdateAdminUiSettingsData $input, string $uuid, Request $request): Response
    {
        return $this->update($input, 'users', $uuid, $request);
    }

    /** The subject a route names, when it exists: `roles` => role, `users` => user. */
    private function subject(string $type, string $uuid): ?string
    {
        return match ($type) {
            'roles' => $this->settings->roleExists($uuid) ? AdminUiSettings::ROLE : null,
            'users' => $this->settings->userExists($uuid) ? AdminUiSettings::USER : null,
            default => null,
        };
    }

    /** A path inside the admin: `/…`, never `//…`, no scheme, no spaces, within the column. */
    private static function isAdminPath(string $path): bool
    {
        return preg_match('~\A/(?!/)[^\s\\\\]*\z~', $path) === 1 && strlen($path) <= self::MAX_PATH;
    }
}
