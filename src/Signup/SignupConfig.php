<?php

declare(strict_types=1);

namespace Thallo\Core\Signup;

use Thallo\Core\Settings\SettingsStore;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Glueful\Notifications\Services\NotificationDispatcher;
use Thallo\Contracts\Settings\SystemChannel;
use Thallo\Tenancy\System\SystemFlags;

final class SignupConfig
{
    public const WORKSPACE_KEY = 'tenancy.signup.workspaces.enabled';
    private const MEMBER_ENABLED = 'signup.members.enabled';
    private const MEMBER_ROLE = 'signup.members.role';

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly SettingsStore $settings,
        private readonly SystemChannel $system,
        private readonly SystemFlags $flags,
        private readonly TenantContextRunner $tenants,
        private readonly SignupRolePolicy $roles,
        private readonly NotificationDispatcher $notifications,
    ) {
    }

    public function memberSignupEnabled(string $tenantUuid): bool
    {
        return $this->inTenant($tenantUuid, fn (): bool => $this->settings->get(self::MEMBER_ENABLED) === '1');
    }

    public function memberSignupRole(string $tenantUuid): string
    {
        return $this->inTenant($tenantUuid, function (): string {
            $role = trim((string) $this->settings->get(self::MEMBER_ROLE));
            return $role === '' ? 'viewer' : $role;
        });
    }

    public function setMemberSignup(string $tenantUuid, bool $enabled, string $role): void
    {
        $role = trim($role);
        if (!$this->roles->isEligible($tenantUuid, $role)) {
            throw new SignupException('The selected role is not eligible for public signup.', 422, [
                'role' => 'Select an active role without workspace-governance capabilities.',
            ]);
        }
        if ($enabled) {
            $this->assertEmailChannel();
        }
        $this->inTenant($tenantUuid, function () use ($enabled, $role): void {
            $this->settings->putMany([
                self::MEMBER_ENABLED => $enabled ? '1' : '0',
                self::MEMBER_ROLE => $role,
            ]);
        });
    }

    public function workspaceSignupConfigured(): bool
    {
        $persisted = $this->system->get(self::WORKSPACE_KEY);
        if ($persisted !== null) {
            return $persisted === '1';
        }
        return (bool) config($this->context, 'signup.workspaces.enabled', false);
    }

    public function workspaceSignupEnabled(): bool
    {
        return $this->workspaceSignupConfigured() && $this->flags->enforcementActive();
    }

    public function setWorkspaceSignup(bool $enabled): void
    {
        if ($enabled) {
            $this->assertEmailChannel();
            if (!$this->flags->enforcementActive()) {
                throw new SignupException('Workspace signup requires active tenant enforcement.', 409);
            }
        }
        $this->system->put(self::WORKSPACE_KEY, $enabled ? '1' : '0');
    }

    public function emailChannelAvailable(): bool
    {
        try {
            $channels = $this->notifications->getChannelManager();
            return $channels->hasChannel('email') && $channels->getChannel('email')->isAvailable();
        } catch (\Throwable) {
            return false;
        }
    }

    private function assertEmailChannel(): void
    {
        if (!$this->emailChannelAvailable()) {
            throw new SignupException(
                'Public signup requires an available email notification channel.',
                422,
                ['email' => 'Configure and enable the email notification channel first.'],
            );
        }
    }

    private function inTenant(string $tenantUuid, callable $operation): mixed
    {
        return $this->tenants->runAsTenant($tenantUuid, function () use ($operation): mixed {
            $this->settings->clearCache();
            try {
                return $operation();
            } finally {
                $this->settings->clearCache();
            }
        });
    }
}
