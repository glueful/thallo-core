<?php

declare(strict_types=1);

namespace Thallo\Core\Account;

use Glueful\Auth\Interfaces\SessionStoreInterface;
use Glueful\Auth\PasswordHasher;
use Glueful\Auth\TokenManager;
use Glueful\Extensions\Users\Repositories\UserRepository;
use Psr\Log\LoggerInterface;
use Thallo\Contracts\Account\AccountProfile;
use Thallo\Contracts\Account\PasswordChange;
use Thallo\Contracts\Account\ProfileUpdate;
use Thallo\Contracts\Account\StorefrontAccountProfile;
use Thallo\Core\Signup\SignupException;
use Thallo\Core\Signup\SignupInput;

/**
 * The storefront profile over `glueful/users`' repository. Names and passwords are held to the
 * rules registration applies ({@see SignupInput}), so an account can never be edited into a
 * shape it could not have been created in.
 */
final class AppStorefrontAccountProfile implements StorefrontAccountProfile
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly SessionStoreInterface $sessions,
        private readonly TokenManager $tokens,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function profile(string $userUuid): ?AccountProfile
    {
        $user = $this->activeUser($userUuid);
        if ($user === null) {
            return null;
        }
        $names = $this->users->findProfileRow($userUuid, ['first_name', 'last_name']) ?? [];

        return new AccountProfile(
            (string) ($user['email'] ?? ''),
            (string) ($names['first_name'] ?? ''),
            (string) ($names['last_name'] ?? ''),
        );
    }

    public function rename(string $userUuid, string $firstName, string $lastName): ProfileUpdate
    {
        try {
            $names = SignupInput::names(['first_name' => $firstName, 'last_name' => $lastName]);
        } catch (SignupException $e) {
            return new ProfileUpdate(false, $e->errors);
        }
        if ($this->activeUser($userUuid) === null || !$this->users->updateProfile($userUuid, $names, $userUuid)) {
            return new ProfileUpdate(false, ['first_name' => 'Your name could not be saved. Try again.']);
        }

        return new ProfileUpdate(true);
    }

    public function changePassword(string $userUuid, string $currentPassword, string $newPassword): PasswordChange
    {
        $user = $this->activeUser($userUuid);
        $stored = $this->users->findAccountRow($userUuid, ['password'])['password'] ?? null;
        $hasher = new PasswordHasher();
        if ($user === null || !is_string($stored) || !$hasher->verify($currentPassword, $stored)) {
            return new PasswordChange(PasswordChange::WRONG_PASSWORD, 'Your current password is wrong.');
        }
        if (($problem = SignupInput::passwordProblem($newPassword)) !== null) {
            return new PasswordChange(PasswordChange::WEAK_PASSWORD, $problem);
        }
        if ($this->users->setNewPassword($userUuid, $hasher->hash($newPassword), 'uuid') !== true) {
            $this->logger->error('A customer password change could not be written', ['user_uuid' => $userUuid]);
            return new PasswordChange(PasswordChange::FAILED, 'Your password could not be changed. Try again.');
        }

        // Only after a confirmed write, as recovery does: whoever else holds a session loses it.
        // The device that asked gets a fresh one so the customer is not signed out by their own
        // change; failing that, they sign in again with the new password.
        $this->sessions->revokeAllForUser($userUuid);
        try {
            $session = $this->tokens->createUserSession($user);
        } catch (\Throwable $e) {
            $this->logger->warning('A customer could not be signed back in after a password change', [
                'error' => $e->getMessage(),
            ]);
            $session = [];
        }

        return new PasswordChange(PasswordChange::CHANGED, '', $session === [] ? null : $session);
    }

    /** @return array<string,mixed>|null */
    private function activeUser(string $userUuid): ?array
    {
        $user = $this->users->findByUuid($userUuid);

        return is_array($user) && (string) ($user['status'] ?? '') === 'active' ? $user : null;
    }
}
