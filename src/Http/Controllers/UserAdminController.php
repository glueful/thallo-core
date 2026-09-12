<?php

declare(strict_types=1);

namespace Thallo\Core\Http\Controllers;

use Thallo\Core\Http\DTOs\CreateUserData;
use Thallo\Core\Http\DTOs\ErrorResponse;
use Thallo\Core\Http\DTOs\UpdateUserData;
use Glueful\Auth\PasswordHasher;
use Thallo\Core\Support\ActorHelper;
use Thallo\Core\Support\AuthorityAudit;
use Thallo\Core\Support\AuthorityContinuityGuard;
use Thallo\Core\Support\AuthorityMutator;
use Thallo\Core\Support\RoleAssignmentException;
use Thallo\Core\Support\UserRoleAssignmentPolicy;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Aegis\AegisPermissionProvider;
use Glueful\Extensions\Aegis\Models\Role;
use Glueful\Extensions\Users\Repositories\UserRepository;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * App-owned admin user management — the *policy* layer over glueful/users' identity-store primitives.
 *
 * glueful/users deliberately exposes only read endpoints (`/me`, `/users`); creating, editing and
 * removing users is product policy (who may, what's required, soft vs hard delete, invites), so it
 * lives here. This composes the extension's `UserRepository` (create/update/uniqueness/soft-delete)
 * and Aegis's `AegisPermissionProvider` (role assignment) — the app orchestrating the two extensions
 * it owns; it never touches the `users`/`profiles`/`user_roles` schema directly. Gating is on the
 * route (`users.create` / `users.edit` / `users.delete`).
 *
 * Roles are assigned by SLUG via the permission provider (the same path SetupService uses for the
 * first admin) — NOT via `RoleService::assignRoleToUser()`, whose actor must out-rank the target in
 * the role hierarchy. Thallo's roles are flat (no `parent_uuid`), so that hierarchy check would always
 * reject an administrator assigning, say, `editor`.
 */
final class UserAdminController
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly UserRepository $users,
        private readonly AegisPermissionProvider $aegis,
        private readonly UserRoleAssignmentPolicy $rolePolicy,
        private readonly AuthorityContinuityGuard $continuity,
        private readonly AuthorityMutator $authorityMutator,
        private readonly AuthorityAudit $audit,
    ) {
    }

    /** POST /v1/admin/users — create an active, pre-verified user with an admin-set password. */
    #[ApiOperation(
        summary: 'Create a user',
        description: 'Creates an active account with an admin-set password and marks the email verified '
            . '(admin-created accounts are trusted, so the user can sign in immediately). `username` and '
            . '`email` must be unique. Optionally assign roles by passing `role_slugs` (applied via the '
            . 'RBAC layer after the account is created). Requires the `users.create` permission.',
        tags: ['Users'],
    )]
    #[ApiResponse(201, description: 'User created; returns the new `uuid`.')]
    #[ApiResponse(401, description: 'Authentication required')]
    #[ApiResponse(403, description: 'Missing the users.create permission')]
    #[ApiResponse(
        422,
        schema: ErrorResponse::class,
        envelope: false,
        description: 'Validation failed (invalid email/username/password, or username/email already taken).',
    )]
    public function store(CreateUserData $input, Request $request): Response
    {
        // Field-specific uniqueness messages (UserRepository::create() also guards, as a backstop).
        if ($this->users->emailExists($input->email)) {
            return Response::validation(['email' => 'A user with this email already exists.']);
        }
        if ($this->users->usernameExists($input->username)) {
            return Response::validation(['username' => 'A user with this username already exists.']);
        }

        // Authorize role assignment BEFORE creating the account, so a denied request never
        // leaves an orphaned user. A new account has no current roles, and is never the actor.
        if ($input->role_slugs !== []) {
            try {
                $this->rolePolicy->assertCanSyncRoles(
                    ActorHelper::uuidFromRequest($request) ?? '',
                    '',
                    [],
                    $input->role_slugs,
                );
            } catch (RoleAssignmentException $e) {
                return $this->roleAssignmentError($e);
            }
        }

        try {
            $uuid = $this->users->create([
                'username' => $input->username,
                'email' => $input->email,
                // UserRepository::create() does NOT hash — the app hashes via the framework hasher.
                'password' => (new PasswordHasher())->hash($input->password),
                'status' => 'active',
                // Admin-created accounts are trusted: mark verified so they can sign in immediately.
                'email_verified_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\InvalidArgumentException $e) {
            // Format/uniqueness failures from create() surface as 422 rather than a 500.
            return Response::validation(['user' => $e->getMessage()]);
        }

        $this->applyProfile($uuid, $input->first_name, $input->last_name);
        $this->syncRoles($uuid, $input->role_slugs);

        return Response::created(['uuid' => $uuid], 'User created.');
    }

    /** PATCH /v1/admin/users/{uuid} — partially update a user's account + profile. */
    #[ApiOperation(
        summary: 'Update a user',
        description: 'Partial update — only the supplied fields change (`username`, `email`, `status`, '
            . '`first_name`, `last_name`, `role_slugs`). `username`/`email` must remain unique. `role_slugs` '
            . 'is optional: omit it to leave roles untouched, or send the full desired set (even `[]`) to '
            . 'replace them. Password is not editable here (it has its own reset flow). '
            . 'Requires the `users.edit` permission.',
        tags: ['Users'],
    )]
    #[ApiResponse(200, description: 'User updated.')]
    #[ApiResponse(401, description: 'Authentication required')]
    #[ApiResponse(403, description: 'Missing the users.edit permission')]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'No user with that UUID.')]
    #[ApiResponse(
        422,
        schema: ErrorResponse::class,
        envelope: false,
        description: 'Validation failed (invalid email/username, or the new username/email is already taken).',
    )]
    public function update(UpdateUserData $input, Request $request, string $uuid): Response
    {
        if ($this->users->findByUuid($uuid) === null) {
            return Response::notFound('User not found.');
        }
        if ($input->email !== null && $this->users->emailExists($input->email, $uuid)) {
            return Response::validation(['email' => 'A user with this email already exists.']);
        }
        if ($input->username !== null && $this->users->usernameExists($input->username, $uuid)) {
            return Response::validation(['username' => 'A user with this username already exists.']);
        }

        $current = $this->currentRoleSlugs($uuid);
        $desired = $input->role_slugs;
        $rolesRemoved = $desired === null ? [] : array_values(array_diff($current, $desired));
        $deactivating = $input->status !== null && $input->status !== '' && $input->status !== 'active';

        if ($desired !== null) {
            try {
                $this->rolePolicy->assertCanSyncRoles(
                    ActorHelper::uuidFromRequest($request) ?? '',
                    $uuid,
                    $current,
                    $desired,
                );
            } catch (RoleAssignmentException $e) {
                return $this->roleAssignmentError($e);
            }
        }

        $account = array_filter(
            ['username' => $input->username, 'email' => $input->email, 'status' => $input->status],
            static fn ($v) => $v !== null && $v !== '',
        );

        $actorUuid = ActorHelper::uuidFromRequest($request);
        try {
            $this->continuity->runExclusive(function () use (
                $actorUuid,
                $uuid,
                $account,
                $desired,
                $rolesRemoved,
                $deactivating,
            ): void {
                if ($rolesRemoved !== [] || $deactivating) {
                    $this->continuity->assertPreservesAuthority(
                        $actorUuid,
                        $uuid,
                        $rolesRemoved,
                        $deactivating,
                        $deactivating ? 'deactivate' : 'roles_sync',
                    );
                }
                if ($account !== [] && !$this->authorityMutator->updateUser($uuid, $account)) {
                    throw new \RuntimeException('User account update failed.');
                }
                if ($desired !== null) {
                    $this->syncRoles($uuid, $desired, transactional: true);
                }
            });
        } catch (RoleAssignmentException $e) {
            return $this->roleAssignmentError($e);
        }

        // Profile fields carry no authority and need not hold the global authority lock.
        $this->applyProfile($uuid, $input->first_name, $input->last_name, allowClear: true);

        if ($desired !== null) {
            foreach ($rolesRemoved as $slug) {
                $this->audit->record('security.role_revoked', $actorUuid, $uuid, ['role' => $slug]);
            }
            foreach (array_values(array_diff($desired, $current)) as $slug) {
                $this->audit->record('security.role_assigned', $actorUuid, $uuid, ['role' => $slug]);
            }
        }

        return Response::success([], 'User updated.');
    }

    /** DELETE /v1/admin/users/{uuid} — soft-delete a user (reversible; row preserved). */
    #[ApiOperation(
        summary: 'Delete a user',
        description: 'Soft-deletes the user (sets `deleted_at`), so the account drops out of the user '
            . 'list/reads and loses access while the row is preserved for restore/audit. You cannot delete '
            . 'your own account. Requires the `users.delete` permission.',
        tags: ['Users'],
    )]
    #[ApiResponse(200, description: 'User deleted.')]
    #[ApiResponse(401, description: 'Authentication required')]
    #[ApiResponse(403, description: 'Missing the users.delete permission')]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'No user with that UUID.')]
    #[ApiResponse(
        422,
        schema: ErrorResponse::class,
        envelope: false,
        description: 'You attempted to delete your own account.',
    )]
    public function destroy(Request $request, string $uuid): Response
    {
        $actorUuid = ActorHelper::uuidFromRequest($request);
        if ($actorUuid !== null && $actorUuid === $uuid) {
            return Response::error('You cannot delete your own account.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($this->users->findByUuid($uuid) === null) {
            return Response::notFound('User not found.');
        }

        try {
            $this->continuity->runExclusive(function () use ($actorUuid, $uuid): void {
                $this->continuity->assertPreservesAuthority($actorUuid, $uuid, [], true, 'delete');
                if (!$this->authorityMutator->softDeleteUser($uuid)) {
                    throw new \RuntimeException('User soft-delete failed.');
                }
            });
        } catch (RoleAssignmentException $e) {
            return $this->roleAssignmentError($e);
        }

        return Response::success([], 'User deleted.');
    }

    /**
     * Persist the user's profile name when supplied. On create, only non-empty values are written; on
     * update, empty strings are allowed through so a name can be cleared (controlled by $allowClear).
     */
    private function applyProfile(string $uuid, ?string $firstName, ?string $lastName, bool $allowClear = false): void
    {
        $keep = $allowClear
            ? static fn ($v) => $v !== null
            : static fn ($v) => $v !== null && $v !== '';
        $profile = array_filter(['first_name' => $firstName, 'last_name' => $lastName], $keep);
        if ($profile !== []) {
            $this->users->updateProfile($uuid, $profile);
        }
    }

    /**
     * Set the user's roles to exactly $roleSlugs via the permission provider — revoke the ones
     * dropped, assign the ones added. Empty set ⇒ the user ends with no roles. Slug-based and free of
     * the actor-hierarchy guard (see the class note); roles live in Aegis, so `user_roles` is never
     * written directly.
     *
     * @param list<string> $roleSlugs
     */
    /**
     * The role slugs a user currently holds (for computing the assignment diff).
     *
     * @return list<string>
     */
    private function currentRoleSlugs(string $userUuid): array
    {
        $slugs = [];
        foreach ($this->aegis->getUserRoles($userUuid) as $role) {
            if ($role instanceof Role) {
                $slugs[] = $role->getSlug();
            }
        }
        return $slugs;
    }

    /** Map a role-assignment policy failure to its HTTP response (403 deny / 422 unknown slug). */
    private function roleAssignmentError(RoleAssignmentException $e): Response
    {
        if ($e->status === 422) {
            return Response::validation(['role_slugs' => $e->getMessage()]);
        }
        return Response::error($e->getMessage(), Response::HTTP_FORBIDDEN, ['code' => 'FORBIDDEN']);
    }

    private function syncRoles(string $userUuid, array $roleSlugs, bool $transactional = false): void
    {
        $want = array_values(
            array_filter(array_map('strval', $roleSlugs), static fn (string $s) => $s !== ''),
        );

        $have = [];
        foreach ($this->aegis->getUserRoles($userUuid) as $role) {
            if ($role instanceof Role) {
                $have[] = $role->getSlug();
            }
        }

        foreach ($have as $slug) {
            if (!in_array($slug, $want, true)) {
                $transactional
                    ? $this->authorityMutator->revokeRole($userUuid, $slug)
                    : $this->aegis->revokeRole($userUuid, $slug);
            }
        }
        foreach ($want as $slug) {
            if (!in_array($slug, $have, true)) {
                $transactional
                    ? $this->authorityMutator->assignRole($userUuid, $slug)
                    : $this->aegis->assignRole($userUuid, $slug);
            }
        }
    }
}
