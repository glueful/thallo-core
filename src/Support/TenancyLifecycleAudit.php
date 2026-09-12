<?php

declare(strict_types=1);

namespace Thallo\Core\Support;

use Glueful\Extensions\Audit\Contracts\AuditRecorderInterface;
use Glueful\Extensions\Audit\Support\AuditEntry;
use Thallo\Contracts\Tenancy\TenancyLifecycleAudit as TenancyLifecycleAuditContract;

final class TenancyLifecycleAudit implements TenancyLifecycleAuditContract
{
    public function __construct(private readonly ?AuditRecorderInterface $audit = null)
    {
    }

    public function record(string $action, ?string $actorUuid, ?string $tenantUuid, array $context = []): void
    {
        if ($this->audit === null) {
            return;
        }
        try {
            $this->audit->record(new AuditEntry(
                occurredAt: microtime(true),
                action: $action,
                category: 'security',
                actorUuid: $actorUuid,
                targetType: 'tenant',
                targetUuid: $tenantUuid,
                context: $context,
            ));
        } catch (\Throwable) {
            // Lifecycle audit is best-effort and cannot change the authoritative mutation outcome.
        }
    }
}
