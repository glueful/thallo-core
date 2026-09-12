<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Indexing;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Queue\QueueManager;

/**
 * The single dispatch seam for {@see EnsureFilterIndexesJob} — the controller and the backfill runner
 * both route through here so they cannot diverge on how the tenant context is captured. It resolves
 * the current tenant via the neutral {@see CurrentTenantResolver} (never a concrete Tenancy\* class),
 * normalizes the resolver's '' sentinel to null (tenancy-off), and pushes a CLOSED payload the job's
 * fail-closed handle() can trust: an explicit `null` `tenant_uuid` means tenancy-off; a 12-char nano-id
 * means "reconcile inside that tenant's context".
 */
final class FilterIndexJobDispatcher
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly QueueManager $queue,
        private readonly ?CurrentTenantResolver $tenants = null,
    ) {
    }

    public function dispatch(string $contentTypeUuid): void
    {
        $uuid = $this->tenants?->tenantUuid($this->context);
        $tenantUuid = ($uuid === null || $uuid === '') ? null : $uuid;

        $this->queue->push(EnsureFilterIndexesJob::class, [
            'content_type_uuid' => $contentTypeUuid,
            'tenant_uuid' => $tenantUuid,
        ]);
    }
}
