<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Media;

use Thallo\Core\Content\Delivery\ThalloCanonicalPublicOriginResolver;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Contracts\Tenancy\FullTenantResolutionReadiness;
use Glueful\Extensions\Contracts\Tenancy\TenantAdministration;
use Glueful\Extensions\Contracts\Tenancy\TenantDomainAdministration;
use Glueful\Uploader\Contracts\BlobPublicUrlProvider;
use RuntimeException;
use Thallo\Contracts\Delivery\CanonicalPublicOriginResolver;
use Thallo\Tenancy\System\SystemFlags;

/** Derives a blob owner's canonical public origin without importing tenancy models. */
final class TenantBlobPublicUrlProvider implements BlobPublicUrlProvider
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly Connection $connection,
        private readonly SystemFlags $flags,
        private readonly ?FullTenantResolutionReadiness $readiness,
        private readonly ?TenantAdministration $tenants,
        private readonly ?TenantDomainAdministration $domains,
    ) {
    }

    public function publicBaseUrl(array $blob): ?string
    {
        if ($this->readiness === null || !$this->readiness->isReady($this->context)) {
            return null;
        }
        if ($this->tenants === null || $this->domains === null) {
            throw new RuntimeException('Tenant administration contracts are unavailable.');
        }

        $blobUuid = is_string($blob['uuid'] ?? null) ? $blob['uuid'] : '';
        $owner = $blobUuid === '' ? null : $this->connection->table('media_assets')
            ->where('blob_uuid', $blobUuid)
            ->select(['tenant_uuid'])
            ->first();
        $tenantUuid = is_array($owner) ? (string) ($owner['tenant_uuid'] ?? '') : '';
        if ($tenantUuid === '') {
            throw new RuntimeException('Cannot derive a canonical origin for an ownerless blob.');
        }

        // Host-selection precedence lives in ONE place — the shared origin contract. This
        // provider owns only the blob -> owning-tenant lookup above.
        return $this->origin()->originForTenant($this->context, $tenantUuid);
    }

    private function origin(): CanonicalPublicOriginResolver
    {
        // Built directly rather than DI-injected: this provider's own constructor is a public
        // contract exercised by direct construction in tests (6 positional args), so it stays
        // unchanged. The resolver is stateless plumbing over the same dependencies already held
        // here; currentOrigin()'s tenant-resolution path is never used from this call site, so no
        // CurrentTenantResolver is threaded through.
        return new ThalloCanonicalPublicOriginResolver($this->flags, null, $this->tenants, $this->domains);
    }
}
