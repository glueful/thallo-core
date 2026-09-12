<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Authorization;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Audit\Contracts\AuditRecorderInterface;
use Glueful\Extensions\Audit\Support\AuditEntry;
use Glueful\Permissions\PermissionManager;
use Symfony\Component\HttpFoundation\Request;

/** Explicit, Aegis-backed operator escalation with best-effort audit recording. */
final class OperatorBypass
{
    private const CAPABILITY_MAP = [
        'tenant.members.manage' => 'tenancy.manage',
        'tenant.domains.manage' => 'tenancy.manage',
        'tenant.roles.manage' => 'tenancy.manage',
    ];

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly ?PermissionManager $permissions = null,
        private readonly ?AuditRecorderInterface $audit = null,
    ) {
    }

    /** @param array<string,mixed> $aegisContext */
    public function evaluate(
        Request $request,
        string $userUuid,
        ?string $membershipRole,
        string $capability,
        string $resolvedTenantUuid,
        array $aegisContext,
    ): BypassDecision {
        $decision = $this->decide(
            $request,
            $userUuid,
            $membershipRole,
            $capability,
            $resolvedTenantUuid,
            $aegisContext,
        );
        if ($decision->granted) {
            $this->recordAudit(
                $request,
                $userUuid,
                $resolvedTenantUuid,
                $capability,
                (string) $decision->mode,
            );
        }

        return $decision;
    }

    /** @param array<string,mixed> $aegisContext */
    public function decide(
        Request $request,
        string $userUuid,
        ?string $membershipRole,
        string $capability,
        string $resolvedTenantUuid,
        array $aegisContext,
    ): BypassDecision {
        $operatorMode = $request->headers->get('X-Tenant-Operator-Mode') === '1';
        if ($membershipRole !== null && !$operatorMode) {
            return new BypassDecision(false, null, 'membership_wins');
        }

        $mode = $membershipRole === null ? 'foreign' : 'escalated';
        $permissions = $this->permissions ?? $this->resolvePermissions();
        if (
            $permissions === null || !$permissions->can(
                $userUuid,
                'tenancy.access_any',
                'thallo',
                $aegisContext,
            )
        ) {
            return new BypassDecision(false, $mode, 'access_any_denied');
        }

        $mapped = self::CAPABILITY_MAP[$capability] ?? null;
        if ($mapped === null && str_starts_with($capability, 'tenant.')) {
            return new BypassDecision(false, $mode, 'unknown_tenant_capability');
        }
        $required = $mapped ?? $capability;
        if (!$permissions->can($userUuid, $required, 'thallo', $aegisContext)) {
            return new BypassDecision(false, $mode, 'capability_denied');
        }

        return new BypassDecision(true, $mode, 'granted');
    }

    private function resolvePermissions(): ?PermissionManager
    {
        if (!$this->context->hasContainer()) {
            return null;
        }
        $container = $this->context->getContainer();
        foreach ([PermissionManager::class, 'permission.manager'] as $id) {
            try {
                if ($container->has($id) && ($manager = $container->get($id)) instanceof PermissionManager) {
                    return $manager;
                }
            } catch (\Throwable) {
                continue;
            }
        }
        return null;
    }

    private function recordAudit(
        Request $request,
        string $userUuid,
        string $tenantUuid,
        string $capability,
        string $mode,
    ): void {
        if ($this->audit === null) {
            return;
        }
        try {
            $this->audit->record(new AuditEntry(
                occurredAt: microtime(true),
                action: 'tenancy.operator_bypass',
                category: 'security',
                actorUuid: $userUuid,
                targetType: 'tenant',
                targetUuid: $tenantUuid,
                context: [
                    'capability' => $capability,
                    'route' => $request->getPathInfo(),
                    'mode' => $mode,
                ],
            ));
        } catch (\Throwable) {
            // The installed audit contract is explicitly best-effort.
        }
    }
}
