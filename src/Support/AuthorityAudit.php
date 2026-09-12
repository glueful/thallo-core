<?php

declare(strict_types=1);

namespace Thallo\Core\Support;

use Glueful\Extensions\Audit\Contracts\AuditRecorderInterface;
use Glueful\Extensions\Audit\Support\AuditEntry;

/** Best-effort authority audit emitter. */
final class AuthorityAudit
{
    public function __construct(private readonly ?AuditRecorderInterface $audit = null)
    {
    }

    /** @param array<string,mixed> $context */
    public function record(string $action, ?string $actorUuid, ?string $targetUuid, array $context): void
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
                targetType: 'user',
                targetUuid: $targetUuid,
                context: $context,
            ));
        } catch (\Throwable) {
            // Best-effort by contract.
        }
    }
}
