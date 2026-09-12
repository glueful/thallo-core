<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Repositories;

use Thallo\Core\Content\Schema\Migration\MigrationOpSet;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\TenantScope;
use Glueful\Helpers\Utils;
use Thallo\Contracts\Tenancy\WriteBarrier;

final class MigrationRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly ?ApplicationContext $context = null,
        private readonly ?CurrentTenantResolver $tenants = null,
        private readonly ?WriteBarrier $barrier = null,
    ) {
    }

    /**
     * Record the migration and flip the content type schema in one framework transaction.
     *
     * @param list<array<string,mixed>> $newSchema
     */
    public function recordAndFlip(
        string $contentTypeUuid,
        int $fromVersion,
        MigrationOpSet $ops,
        array $newSchema,
        int $workItemsTotal,
        ?string $actor,
    ): string {
        $uuid = Utils::generateNanoID(12);
        $now = $this->now();

        $this->db->transaction(function () use (
            $uuid,
            $contentTypeUuid,
            $fromVersion,
            $ops,
            $newSchema,
            $workItemsTotal,
            $actor,
            $now
        ): void {
            $this->db->table('entry_schema_migrations')->insert([
                'uuid' => $uuid,
                'content_type_uuid' => $contentTypeUuid,
                'from_version' => $fromVersion,
                'to_version' => $fromVersion + 1,
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

            $this->db->table('content_types')
                ->where('uuid', '=', $contentTypeUuid)
                ->update([
                    'schema' => json_encode($newSchema, JSON_THROW_ON_ERROR),
                    'schema_version' => $fromVersion + 1,
                    'updated_at' => $now,
                ]);
        });

        return $uuid;
    }

    /** @return array<string,mixed>|null */
    public function activeForType(string $contentTypeUuid): ?array
    {
        return $this->hydrate($this->db->table('entry_schema_migrations')
            ->where('content_type_uuid', '=', $contentTypeUuid)
            ->whereIn('status', ['pending', 'running'])
            ->orderBy('id', 'ASC')
            ->first());
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        return $this->hydrate($this->db->table('entry_schema_migrations')
            ->where('uuid', '=', $uuid)
            ->first());
    }

    /** @return list<array<string,mixed>> */
    public function forType(string $contentTypeUuid): array
    {
        return array_values(array_filter(array_map(
            fn (array $row): ?array => $this->hydrate($row),
            $this->db->table('entry_schema_migrations')
                ->where('content_type_uuid', '=', $contentTypeUuid)
                ->orderBy('id', 'ASC')
                ->get()
        )));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function chainFor(string $contentTypeUuid, int $afterVersion): array
    {
        return array_values(array_filter(array_map(
            fn (array $row): ?array => $this->hydrate($row),
            $this->db->table('entry_schema_migrations')
                ->where('content_type_uuid', '=', $contentTypeUuid)
                ->where('from_version', '>=', $afterVersion)
                ->orderBy('from_version', 'ASC')
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
                'UPDATE entry_schema_migrations
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

        $this->db->table('entry_schema_migrations')
            ->where('uuid', '=', $uuid)
            ->update([
                'work_items_failed' => (int) $row['work_items_failed'] + 1,
                'failure_report' => json_encode($report, JSON_THROW_ON_ERROR),
            ]);
    }

    public function resetFailures(string $uuid): void
    {
        $this->db->table('entry_schema_migrations')
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

        $this->db->table('entry_schema_migrations')
            ->where('uuid', '=', $uuid)
            ->update([
                'status' => $status,
                'completed_at' => $this->now(),
            ]);
    }

    /** @param array<string,mixed>|null $row */
    private function hydrate(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }

        $row['from_version'] = (int) $row['from_version'];
        $row['to_version'] = (int) $row['to_version'];
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

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
