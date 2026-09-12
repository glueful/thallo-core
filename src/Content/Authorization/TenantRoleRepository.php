<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Authorization;

use Glueful\Database\Connection;

final class TenantRoleRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return array{tenant_uuid:string,slug:string,name:string,status:string}|null */
    public function find(string $tenantUuid, string $slug): ?array
    {
        $row = $this->connection->table('tenant_roles')
            ->where('tenant_uuid', '=', $tenantUuid)->where('slug', '=', $slug)->first();
        return is_array($row) ? [
            'tenant_uuid' => (string) $row['tenant_uuid'],
            'slug' => (string) $row['slug'],
            'name' => (string) $row['name'],
            'status' => (string) $row['status'],
        ] : null;
    }

    public function isActive(string $tenantUuid, string $slug): bool
    {
        return ($this->find($tenantUuid, $slug)['status'] ?? null) === 'active';
    }

    /** @return list<array{tenant_uuid:string,slug:string,name:string,status:string}> */
    public function all(string $tenantUuid, bool $activeOnly = false): array
    {
        $query = $this->connection->table('tenant_roles')->where('tenant_uuid', '=', $tenantUuid);
        if ($activeOnly) {
            $query->where('status', '=', 'active');
        }
        return array_values(array_map(static fn (array $row): array => [
            'tenant_uuid' => (string) $row['tenant_uuid'],
            'slug' => (string) $row['slug'],
            'name' => (string) $row['name'],
            'status' => (string) $row['status'],
        ], $query->orderBy('name', 'asc')->get()));
    }
}
