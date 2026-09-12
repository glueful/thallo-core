<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Authorization;

use Glueful\Database\Connection;
use LogicException;
use PDO;
use Thallo\Contracts\Tenancy\WriteBarrier;
use Glueful\Extensions\Tenancy\Membership\MembershipRoleLock;

final class TenantRoleOverrideRepository
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CapabilityCatalog $catalog,
        private readonly WriteBarrier $barrier,
        private readonly TenantRoleRepository $roles,
        private readonly MembershipRoleLock $roleLock,
    ) {
    }

    /**
     * @param list<string> $grants
     * @param list<string> $revokes
     * @return array{version:int,set:list<array{capability:string,effect:string}>,cleared:list<array{capability:string,effect:string}>}
     */
    public function reconcileRoleOverridesInTransaction(
        string $tenantUuid,
        string $roleSlug,
        array $grants,
        array $revokes,
        ?string $actorUuid,
    ): array {
        return $this->barrier->runWritable(fn (): array => $this->reconcile(
            $tenantUuid,
            $roleSlug,
            $grants,
            $revokes,
            $actorUuid,
        ));
    }

    /**
     * @param list<string> $grants
     * @param list<string> $revokes
     * @return array{version:int,set:list<array{capability:string,effect:string}>,cleared:list<array{capability:string,effect:string}>}
     */
    private function reconcile(
        string $tenantUuid,
        string $roleSlug,
        array $grants,
        array $revokes,
        ?string $actorUuid,
    ): array {
        $this->assertTransaction();
        if (!in_array($roleSlug, $this->catalog->reservedRoles(), true)) {
            $context = $this->connection->getContext()
                ?? throw new LogicException('Role policy connection has no application context.');
            $this->roleLock->lock($context, $tenantUuid, $roleSlug);
        }
        [$grants, $revokes] = $this->validateDesiredSet($tenantUuid, $roleSlug, $grants, $revokes);
        $desired = [];
        foreach ($grants as $capability) {
            $desired[$capability] = 'grant';
        }
        foreach ($revokes as $capability) {
            $desired[$capability] = 'revoke';
        }
        $current = $this->overridesForRole($tenantUuid, $roleSlug);
        $set = [];
        foreach ($desired as $capability => $effect) {
            if (($current[$capability] ?? null) === $effect) {
                continue;
            }
            $this->connection->getPDO()->prepare(
                'INSERT INTO tenant_role_overrides '
                . '(tenant_uuid,role_slug,capability,effect,created_by,created_at,updated_at) '
                . 'VALUES (?,?,?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP) '
                . 'ON CONFLICT (tenant_uuid,role_slug,capability) DO UPDATE SET '
                . 'effect=EXCLUDED.effect,created_by=EXCLUDED.created_by,updated_at=CURRENT_TIMESTAMP'
            )->execute([$tenantUuid, $roleSlug, $capability, $effect, $actorUuid]);
            $set[] = ['capability' => $capability, 'effect' => $effect];
        }
        $cleared = [];
        foreach ($current as $capability => $effect) {
            if (isset($desired[$capability])) {
                continue;
            }
            $this->connection->getPDO()->prepare(
                'DELETE FROM tenant_role_overrides WHERE tenant_uuid=? AND role_slug=? AND capability=?'
            )->execute([$tenantUuid, $roleSlug, $capability]);
            $cleared[] = ['capability' => $capability, 'effect' => $effect];
        }
        return ['version' => $this->bumpVersionInTransaction($tenantUuid), 'set' => $set, 'cleared' => $cleared];
    }

    /** @return array{version:int,cleared:list<array{role_slug:string,capability:string,effect:string}>} */
    public function clearTenantOverridesInTransaction(string $tenantUuid): array
    {
        return $this->barrier->runWritable(fn (): array => $this->clearTenantOverrides($tenantUuid));
    }

    /** @return array{version:int,cleared:list<array{role_slug:string,capability:string,effect:string}>} */
    private function clearTenantOverrides(string $tenantUuid): array
    {
        $this->assertTransaction();
        $stmt = $this->connection->getPDO()->prepare(
            'SELECT role_slug,capability,effect FROM tenant_role_overrides WHERE tenant_uuid=? '
            . 'ORDER BY role_slug,capability'
        );
        $stmt->execute([$tenantUuid]);
        $cleared = array_values($stmt->fetchAll(PDO::FETCH_ASSOC));
        $this->connection->getPDO()->prepare(
            'DELETE FROM tenant_role_overrides WHERE tenant_uuid=?'
        )->execute([$tenantUuid]);
        return ['version' => $this->bumpVersionInTransaction($tenantUuid), 'cleared' => $cleared];
    }

    /** @return array<string,array<string,string>> */
    public function overridesFor(string $tenantUuid): array
    {
        $stmt = $this->connection->getPDO()->prepare(
            'SELECT role_slug,capability,effect FROM tenant_role_overrides WHERE tenant_uuid=? '
            . "ORDER BY role_slug,capability,effect='revoke' DESC"
        );
        $stmt->execute([$tenantUuid]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $role = (string) $row['role_slug'];
            $capability = (string) $row['capability'];
            if (($result[$role][$capability] ?? null) === 'revoke') {
                continue;
            }
            $result[$role][$capability] = (string) $row['effect'];
        }
        return $result;
    }

    public function hasAnyOverrides(string $tenantUuid, string $roleSlug): bool
    {
        $stmt = $this->connection->getPDO()->prepare(
            'SELECT 1 FROM tenant_role_overrides WHERE tenant_uuid=? AND role_slug=? LIMIT 1'
        );
        $stmt->execute([$tenantUuid, $roleSlug]);
        return $stmt->fetchColumn() !== false;
    }

    public function policyVersion(string $tenantUuid): int
    {
        $stmt = $this->connection->getPDO()->prepare(
            'SELECT version FROM tenant_role_policy WHERE tenant_uuid=?'
        );
        $stmt->execute([$tenantUuid]);
        $version = $stmt->fetchColumn();
        return $version === false ? 0 : (int) $version;
    }

    /** @return list<array{tenant_uuid:string,role_slug:string,capability:string}> */
    public function driftRows(): array
    {
        $rows = $this->connection->table('tenant_role_overrides')
            ->select(['tenant_uuid', 'role_slug', 'capability'])
            ->orderBy('tenant_uuid', 'asc')->orderBy('role_slug', 'asc')->get();
        return array_values(array_filter(array_map(function (array $row): ?array {
            $capability = (string) $row['capability'];
            return $this->catalog->has($capability) ? null : [
                'tenant_uuid' => (string) $row['tenant_uuid'],
                'role_slug' => (string) $row['role_slug'],
                'capability' => $capability,
            ];
        }, $rows)));
    }

    /** @return array<string,string> */
    private function overridesForRole(string $tenantUuid, string $roleSlug): array
    {
        return $this->overridesFor($tenantUuid)[$roleSlug] ?? [];
    }

    /** @param list<string> $grants @param list<string> $revokes @return array{list<string>,list<string>} */
    public function validateDesiredSet(
        string $tenantUuid,
        string $roleSlug,
        array $grants,
        array $revokes,
    ): array {
        if (!in_array($roleSlug, $this->catalog->reservedRoles(), true)) {
            if ($this->roles->find($tenantUuid, $roleSlug) === null) {
                throw new RoleOverrideException('Unknown workspace role.', ['role_slug' => 'Unknown workspace role.']);
            }
            if ($revokes !== []) {
                throw new RoleOverrideException(
                    'Custom roles have no baseline to revoke.',
                    ['revokes' => 'Custom roles accept grants only.'],
                );
            }
        }
        $grants = $this->normalizeCapabilities($grants);
        $revokes = $this->normalizeCapabilities($revokes);
        $errors = [];
        foreach ([...$grants, ...$revokes] as $capability) {
            if (!$this->catalog->isGrantable($capability)) {
                $errors[$capability] = 'Capability is not grantable in a workspace.';
            }
        }
        foreach (array_intersect($grants, $revokes) as $capability) {
            $errors[$capability] = 'Capability cannot be both granted and revoked.';
        }
        if ($roleSlug === 'owner') {
            foreach (array_intersect($revokes, $this->catalog->ownerFloor()) as $capability) {
                $errors[$capability] = 'The owner governance floor cannot be revoked.';
            }
        }
        if ($errors !== []) {
            throw new RoleOverrideException('Workspace role overrides are invalid.', $errors);
        }
        return [$grants, $revokes];
    }

    /** @param list<string> $capabilities @return list<string> */
    private function normalizeCapabilities(array $capabilities): array
    {
        $result = array_values(array_unique(array_map('trim', array_filter($capabilities, 'is_string'))));
        sort($result);
        return $result;
    }

    public function bumpVersionInTransaction(string $tenantUuid): int
    {
        $stmt = $this->connection->getPDO()->prepare(
            'INSERT INTO tenant_role_policy (tenant_uuid,version,updated_at) '
            . 'VALUES (?,1,CURRENT_TIMESTAMP) ON CONFLICT (tenant_uuid) DO UPDATE SET '
            . 'version=tenant_role_policy.version+1,updated_at=CURRENT_TIMESTAMP RETURNING version'
        );
        $stmt->execute([$tenantUuid]);
        return (int) $stmt->fetchColumn();
    }

    public function deleteRoleOverridesInTransaction(string $tenantUuid, string $roleSlug): void
    {
        $this->assertTransaction();
        $this->connection->table('tenant_role_overrides')
            ->where('tenant_uuid', '=', $tenantUuid)->where('role_slug', '=', $roleSlug)->delete();
    }


    private function assertTransaction(): void
    {
        if ($this->connection->transactionLevel() === 0) {
            throw new LogicException('Role policy mutations require an active transaction.');
        }
    }
}
