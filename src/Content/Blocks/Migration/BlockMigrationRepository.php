<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Blocks\Migration;

use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Content\Schema\Migration\MigrationOpSet;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\TenantScope;
use Glueful\Helpers\Utils;
use Thallo\Contracts\Tenancy\WriteBarrier;

/**
 * Block-type migration rows (block-migrations spec §2). NO version numbers: block
 * instances carry no schema stamp, so the MICROSECOND created_at ordering IS the
 * chain identity — restore applies the completed suffix strictly after a version's
 * created_at (spec §5). Running AND failed rows both count as ACTIVE (spec §3):
 * a failed backfill keeps the write gate closed and blocks new declarations until
 * re-driven to completion.
 */
final class BlockMigrationRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly BlockTypeRepository $blockTypes,
        private readonly ?ApplicationContext $context = null,
        private readonly ?CurrentTenantResolver $tenants = null,
        private readonly ?WriteBarrier $barrier = null,
    ) {
    }

    /**
     * Record the migration and flip the block-type schema in one transaction —
     * the flip goes through the guard-exempt internal path (the computed post-op
     * schema legitimately removes/renames fields).
     *
     * @param list<array<string,mixed>> $newSchema
     */
    public function recordAndFlip(
        string $blockTypeUuid,
        MigrationOpSet $ops,
        array $newSchema,
        int $workItemsTotal,
        ?string $actor,
    ): string {
        $uuid = Utils::generateNanoID(12);
        $now = $this->now();

        $this->db->transaction(function () use (
            $uuid,
            $blockTypeUuid,
            $ops,
            $newSchema,
            $workItemsTotal,
            $actor,
            $now
        ): void {
            $this->db->table('block_type_migrations')->insert([
                'uuid' => $uuid,
                'block_type_uuid' => $blockTypeUuid,
                'ops' => json_encode($ops->toArray(), JSON_THROW_ON_ERROR),
                'status' => 'running',
                'work_items_total' => $workItemsTotal,
                'work_items_done' => 0,
                'work_items_failed' => 0,
                'failure_report' => json_encode([], JSON_THROW_ON_ERROR),
                'created_by' => $actor,
                'created_at' => $now,
                'started_at' => $now,
            ]);

            $this->blockTypes->applyMigratedSchema($blockTypeUuid, $newSchema);
        });

        return $uuid;
    }

    /** @return array<string,mixed>|null running OR failed — both are ACTIVE (spec §3) */
    public function activeForType(string $blockTypeUuid): ?array
    {
        return $this->hydrate($this->db->table('block_type_migrations')
            ->where('block_type_uuid', '=', $blockTypeUuid)
            ->whereIn('status', ['running', 'failed'])
            ->orderBy('id', 'ASC')
            ->first());
    }

    /**
     * Every active (running|failed) migration with its block-type slug — the write
     * gate's one cheap query (usually empty).
     *
     * @return list<array{uuid: string, block_type_uuid: string, status: string, slug: string}>
     */
    public function activeAny(): array
    {
        // Use unaliased table names so the tenancy read-scope hook (which matches the exact
        // registered owned-table name passed to table()) injects its tenant_uuid predicate on the
        // primary table; an inline `as` alias would defeat that and the guard would fail closed.
        $rows = $this->db->table('block_type_migrations')
            ->join('block_types', 'block_types.uuid', '=', 'block_type_migrations.block_type_uuid')
            ->select([
                'block_type_migrations.uuid',
                'block_type_migrations.block_type_uuid',
                'block_type_migrations.status',
                'block_types.slug',
            ])
            ->whereIn('block_type_migrations.status', ['running', 'failed'])
            ->get();
        return array_map(static fn(array $r): array => [
            'uuid' => (string) $r['uuid'],
            'block_type_uuid' => (string) $r['block_type_uuid'],
            'status' => (string) $r['status'],
            'slug' => (string) $r['slug'],
        ], $rows);
    }

    /**
     * The restore suffix (spec §5): COMPLETED migrations for this type STRICTLY
     * after the version's creation, oldest first. Microsecond precision on both
     * sides; ties apply nothing (the only same-instant writer is the backfill).
     *
     * @return list<array<string,mixed>>
     */
    public function completedAfter(string $blockTypeUuid, string $versionCreatedAt): array
    {
        return array_values(array_filter(array_map(
            fn(array $row): ?array => $this->hydrate($row),
            $this->db->table('block_type_migrations')
                ->where('block_type_uuid', '=', $blockTypeUuid)
                ->where('status', '=', 'completed')
                ->where('created_at', '>', $versionCreatedAt)
                ->orderBy('created_at', 'ASC')
                ->get()
        )));
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        return $this->hydrate($this->db->table('block_type_migrations')
            ->where('uuid', '=', $uuid)
            ->first());
    }

    /** @return list<array<string,mixed>> */
    public function forType(string $blockTypeUuid): array
    {
        return array_values(array_filter(array_map(
            fn(array $row): ?array => $this->hydrate($row),
            $this->db->table('block_type_migrations')
                ->where('block_type_uuid', '=', $blockTypeUuid)
                ->orderBy('id', 'ASC')
                ->get()
        )));
    }

    public function incrementDone(string $uuid): void
    {
        $tenant = TenantScope::current($this->tenants, $this->context);
        $scope = $tenant === null ? '' : ' AND tenant_uuid = :tenant';
        $params = ['uuid' => $uuid];
        if ($tenant !== null) {
            $params['tenant'] = $tenant;
        }
        $write = function () use ($scope, $params): bool {
            $stmt = $this->db->getPDO()->prepare(
                'UPDATE block_type_migrations
                 SET work_items_done = work_items_done + 1
                 WHERE uuid = :uuid' . $scope
            );
            return $stmt->execute($params);
        };
        $this->barrier !== null ? $this->barrier->runWritable($write) : $write();
    }

    public function recordFailure(string $uuid, string $entryUuid, string $locale, string $kind, string $reason): void
    {
        $row = $this->find($uuid);
        if ($row === null) {
            return;
        }

        $report = $row['failure_report'];
        $report[] = [
            'entry' => $entryUuid,
            'locale' => $locale,
            'kind' => $kind,
            'reason' => $reason,
        ];

        $this->db->table('block_type_migrations')
            ->where('uuid', '=', $uuid)
            ->update([
                'work_items_failed' => (int) $row['work_items_failed'] + 1,
                'failure_report' => json_encode($report, JSON_THROW_ON_ERROR),
            ]);
    }

    public function resetFailures(string $uuid): void
    {
        $this->db->table('block_type_migrations')
            ->where('uuid', '=', $uuid)
            ->update([
                'work_items_failed' => 0,
                'failure_report' => json_encode([], JSON_THROW_ON_ERROR),
                'status' => 'running',
                'completed_at' => null,
            ]);
    }

    public function finish(string $uuid, string $status): void
    {
        if (!in_array($status, ['completed', 'failed'], true)) {
            throw new \InvalidArgumentException('Migration finish status must be completed or failed.');
        }

        $this->db->table('block_type_migrations')
            ->where('uuid', '=', $uuid)
            ->update([
                'status' => $status,
                'completed_at' => $status === 'completed' ? $this->now() : null,
            ]);
    }

    /** @param array<string,mixed>|null $row */
    private function hydrate(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }

        $row['work_items_total'] = (int) $row['work_items_total'];
        $row['work_items_done'] = (int) $row['work_items_done'];
        $row['work_items_failed'] = (int) $row['work_items_failed'];
        $row['ops'] = is_string($row['ops'] ?? null)
            ? (json_decode((string) $row['ops'], true) ?? [])
            : (array) ($row['ops'] ?? []);
        $row['failure_report'] = is_string($row['failure_report'] ?? null)
            ? (json_decode((string) $row['failure_report'], true) ?? [])
            : (array) ($row['failure_report'] ?? []);

        return $row;
    }

    /** Microsecond precision — the chain identity depends on it (spec §5). */
    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s.u');
    }
}
