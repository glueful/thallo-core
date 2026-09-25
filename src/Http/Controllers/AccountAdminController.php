<?php

declare(strict_types=1);

namespace Thallo\Core\Http\Controllers;

use Glueful\Auth\Interfaces\SessionStoreInterface;
use Glueful\Auth\JWTService;
use Glueful\Auth\PasswordHasher;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Users\Repositories\UserRepository;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Http\DTOs\ChangePasswordData;
use Thallo\Core\Http\DTOs\UpdateAccountData;
use Thallo\Core\Support\ActorHelper;

/**
 * The signed-in user's own account — the admin's Profile and Security pages. Every action is on the
 * caller's account and only theirs: there is no user id to name, so no permission gates it beyond
 * being signed in. Email and username stay an administrator's to change (Users).
 */
final class AccountAdminController
{
    /** The shortest password accepted, as for an account an administrator creates. */
    private const MIN_PASSWORD = 8;

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly UserRepository $users,
        private readonly ?SessionStoreInterface $sessions = null,
    ) {
    }

    /** GET /v1/admin/account */
    #[ApiOperation(
        summary: 'Your own account',
        description: 'The signed-in user\'s account and profile, and `two_factor_available`: whether '
            . 'email two-factor authentication is switched on for this install (`TWO_FACTOR_ENABLED`). '
            . 'While it is off, turning it on for an account would never ask for a code.',
        tags: ['Thallo Admin'],
    )]
    #[ApiResponse(200, description: 'The account.')]
    #[ApiResponse(401, description: 'Not signed in.')]
    public function show(Request $request): Response
    {
        $uuid = ActorHelper::uuidFromRequest($request);
        if ($uuid === null) {
            return Response::unauthorized('Authentication required');
        }
        return Response::success($this->account($uuid));
    }

    /** PATCH /v1/admin/account */
    #[ApiOperation(
        summary: 'Update your own profile',
        description: 'Sets the signed-in user\'s first name, last name and photo. An absent field is left '
            . 'as it is; an empty string clears it. `photo_url` is a site path (`/v1/blobs/…`) or an '
            . 'http(s) address. Returns the account and profile.',
        tags: ['Thallo Admin'],
    )]
    #[ApiResponse(200, description: 'Updated.')]
    #[ApiResponse(401, description: 'Not signed in.')]
    #[ApiResponse(422, description: 'A photo that is not a site path or a web address.')]
    public function update(UpdateAccountData $input, Request $request): Response
    {
        $uuid = ActorHelper::uuidFromRequest($request);
        if ($uuid === null) {
            return Response::unauthorized('Authentication required');
        }
        $photo = $input->photo_url === null ? null : trim($input->photo_url);
        if ($photo !== null && $photo !== '' && !self::isPhotoAddress($photo)) {
            return Response::validation(['photo_url' => 'must be a site path or an http(s) address']);
        }
        $profile = [];
        $fields = ['first_name' => $input->first_name, 'last_name' => $input->last_name, 'photo_url' => $photo];
        foreach ($fields as $key => $value) {
            if ($value !== null) {
                $value = trim($value);
                $profile[$key] = $value === '' ? null : $value;
            }
        }
        if ($profile !== []) {
            $this->users->updateProfile($uuid, $profile, $uuid);
        }
        return Response::success($this->account($uuid), 'Profile updated.');
    }

    /** POST /v1/admin/account/password */
    #[ApiOperation(
        summary: 'Change your own password',
        description: 'Changes the signed-in user\'s password. `current_password` must be the password in '
            . 'use; `password` is at least eight characters. Every other session of the account is '
            . 'signed out; this one stays signed in.',
        tags: ['Thallo Admin'],
    )]
    #[ApiResponse(200, description: 'Changed; other sessions signed out.')]
    #[ApiResponse(401, description: 'Not signed in.')]
    #[ApiResponse(422, description: 'A wrong current password, or a new one too short.')]
    public function password(ChangePasswordData $input, Request $request): Response
    {
        $uuid = ActorHelper::uuidFromRequest($request);
        if ($uuid === null) {
            return Response::unauthorized('Authentication required');
        }
        $hasher = new PasswordHasher();
        $row = $this->users->findAccountRow($uuid, ['password']);
        $current = is_array($row) ? (string) ($row['password'] ?? '') : '';
        if ($current === '' || !$hasher->verify($input->current_password, $current)) {
            return Response::validation(['current_password' => 'is not your current password']);
        }
        if (mb_strlen($input->password) < self::MIN_PASSWORD) {
            return Response::validation(['password' => 'must be at least ' . self::MIN_PASSWORD . ' characters']);
        }
        if (!$this->users->setNewPassword($uuid, $hasher->hash($input->password), 'uuid')) {
            return Response::error('The password could not be changed.', 500);
        }
        $signedOut = $this->signOutOtherSessions($uuid, $this->currentSessionId($request));
        return Response::success(['other_sessions_signed_out' => $signedOut], 'Password changed.');
    }

    /** @return array<string,mixed> the account and its profile, as the Profile page shows them */
    private function account(string $uuid): array
    {
        $account = $this->users->findAccountRow($uuid, ['uuid', 'username', 'email', 'two_factor_enabled']) ?? [];
        $profile = $this->users->findProfileRow($uuid, ['first_name', 'last_name', 'photo_url']) ?? [];
        return $account + [
            'profile' => [
                'first_name' => $profile['first_name'] ?? null,
                'last_name' => $profile['last_name'] ?? null,
                'photo_url' => $profile['photo_url'] ?? null,
            ],
            'two_factor_available' => (bool) config($this->context, 'auth.two_factor.enabled', false),
        ];
    }

    /** A site path (`/…`, never `//…`) or an http(s) address: nothing a browser would run. */
    private static function isPhotoAddress(string $value): bool
    {
        if (str_starts_with($value, '/')) {
            return !str_starts_with($value, '//') && strlen($value) <= 500;
        }
        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true)
            && filter_var($value, FILTER_VALIDATE_URL) !== false
            && strlen($value) <= 500;
    }

    /** The session the request is made in: the `sid` claim of its bearer token, or '' without one. */
    private function currentSessionId(Request $request): string
    {
        $header = (string) $request->headers->get('Authorization', '');
        if (!str_starts_with($header, 'Bearer ')) {
            return '';
        }
        $claims = JWTService::decode(substr($header, 7));
        return is_array($claims) && is_string($claims['sid'] ?? null) ? $claims['sid'] : '';
    }

    /** Revoke every active session of the account except `$keep`; returns how many were revoked. */
    private function signOutOtherSessions(string $uuid, string $keep): int
    {
        if ($this->sessions === null) {
            return 0;
        }
        $rows = db($this->context)->table('auth_sessions')
            ->select(['uuid'])
            ->where(['user_uuid' => $uuid, 'status' => 'active'])
            ->get();
        $revoked = 0;
        foreach ($rows as $row) {
            $session = (string) ($row['uuid'] ?? '');
            if ($session !== '' && $session !== $keep && $this->sessions->revoke($session)) {
                $revoked++;
            }
        }
        return $revoked;
    }
}
