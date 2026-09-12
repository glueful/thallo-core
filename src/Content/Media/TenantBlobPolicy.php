<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Media;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\TenantRuntimeReadiness;
use Glueful\Uploader\Contracts\BlobAccessContext;
use Glueful\Uploader\Contracts\BlobAccessPolicy;
use Glueful\Uploader\Contracts\BlobCreatedHook;
use RuntimeException;
use Thallo\Contracts\Tenancy\WriteBarrier;
use Thallo\Contracts\Tenancy\TenantWriteScope;
use Thallo\Tenancy\System\SystemFlags;

final class TenantBlobPolicy implements BlobCreatedHook, BlobAccessPolicy
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly Connection $connection,
        private readonly SystemFlags $flags,
        private readonly TenantRuntimeReadiness $readiness,
        private readonly WriteBarrier $barrier,
        private readonly ?CurrentTenantResolver $resolver = null,
        private readonly ?TenantWriteScope $writeScope = null,
    ) {
    }

    public function onBlobCreated(string $blobUuid, ?string $uploaderUserUuid): void
    {
        if (!$this->flags->tenancyEnabled() && $this->writeScope?->mode() !== 'compat') {
            return;
        }

        $tenantUuid = $this->writeScope?->mode() === 'compat'
            ? $this->writeScope->tenantUuidForWrite()
            : $this->currentTenantUuid();
        if ($tenantUuid === null) {
            throw new RuntimeException('No tenant resolved for blob attribution.');
        }

        $this->barrier->runWritable(function () use ($blobUuid, $tenantUuid): void {
            $statement = $this->connection->getPDO()->prepare(
                'INSERT INTO media_assets (blob_uuid, tenant_uuid, created_at)
                 VALUES (:blob, :tenant, CURRENT_TIMESTAMP)
                 ON CONFLICT (blob_uuid) DO NOTHING
                 RETURNING tenant_uuid'
            );
            $statement->execute(['blob' => $blobUuid, 'tenant' => $tenantUuid]);
            $insertedOwner = $statement->fetchColumn();
            if ($insertedOwner !== false) {
                return;
            }

            $existing = $this->connection->getPDO()->prepare(
                'SELECT tenant_uuid FROM media_assets WHERE blob_uuid = :blob'
            );
            $existing->execute(['blob' => $blobUuid]);
            if ($existing->fetchColumn() !== $tenantUuid) {
                throw new RuntimeException('Blob ownership already belongs to another tenant.');
            }
        });
    }

    public function authorizeAccess(array $blob, BlobAccessContext $context): bool
    {
        if (!$this->flags->tenancyEnabled()) {
            return true;
        }

        $tenantUuid = $this->currentTenantUuid();
        $blobUuid = is_string($blob['uuid'] ?? null) ? $blob['uuid'] : '';
        if ($tenantUuid === null || $blobUuid === '') {
            return false;
        }

        $statement = $this->connection->getPDO()->prepare(
            'SELECT tenant_uuid FROM media_assets WHERE blob_uuid = :blob'
        );
        $statement->execute(['blob' => $blobUuid]);
        $owner = $statement->fetchColumn();

        return is_string($owner) && $owner !== '' && hash_equals($owner, $tenantUuid);
    }

    private function currentTenantUuid(): ?string
    {
        if ($this->resolver !== null) {
            $resolved = $this->resolver->tenantUuid($this->context);
            if ($resolved !== '') {
                return $resolved;
            }
        }

        if ($this->readiness->mode($this->context) !== TenantRuntimeReadiness::MODE_BOOTSTRAP_DEFAULT) {
            return null;
        }

        return $this->flags->defaultTenantUuid();
    }
}
