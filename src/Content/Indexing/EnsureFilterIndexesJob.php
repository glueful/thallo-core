<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Indexing;

use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Glueful\Helpers\Utils;
use Glueful\Queue\Job;
use Psr\Log\LoggerInterface;
use Thallo\Contracts\Tenancy\WriteBarrier;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Reconciles a content type's filterable-field expression indexes against the registry.
 *
 * For each *desired* index (from the type's current schema) missing from the registry it runs
 * `CREATE INDEX CONCURRENTLY IF NOT EXISTS ... ON entry_versions (<expr>)`; for each registry
 * row whose field is no longer filterable it runs `DROP INDEX CONCURRENTLY IF EXISTS ...`.
 * Idempotent.
 *
 * `CREATE INDEX CONCURRENTLY` cannot run inside a transaction, so the DDL is executed via the
 * raw PDO (`Connection::getPDO()->exec()`) outside any transaction — never through the query
 * builder's transactional path. On a host that forbids CONCURRENTLY (managed Postgres) the job
 * logs and marks the registry row `failed` instead of crashing (Task 4 enforces "unindexed ⇒
 * not filterable").
 *
 * Dependencies are resolved from the container at run time because the queue reconstructs a job
 * from its serialized payload + context (constructor is `(array $data, ?ApplicationContext)`).
 */
final class EnsureFilterIndexesJob extends Job
{
    private const TABLE = 'entry_versions';

    public function handle(): void
    {
        $context = $this->context;
        if (!$context instanceof ApplicationContext) {
            throw new \RuntimeException('EnsureFilterIndexesJob requires an ApplicationContext to run.');
        }

        $data = $this->getData();
        $typeUuid = isset($data['content_type_uuid']) && is_string($data['content_type_uuid'])
            ? $data['content_type_uuid']
            : '';
        if ($typeUuid === '') {
            throw new \InvalidArgumentException('EnsureFilterIndexesJob: missing content_type_uuid.');
        }

        $container = $context->getContainer();
        /** @var Connection $db */
        $db = $container->get(Connection::class);
        /** @var ContentTypeRepository $types */
        $types = $container->get(ContentTypeRepository::class);
        $logger = $container->has(LoggerInterface::class) ? $container->get(LoggerInterface::class) : null;
        $barrier = $container->has(WriteBarrier::class) ? $container->get(WriteBarrier::class) : null;

        $reconcile = function () use ($db, $types, $typeUuid, $logger, $barrier): void {
            $this->reconcile($db, $types, $typeUuid, $logger, $barrier);
        };

        // Closed shape: ONLY an explicit null tenant_uuid takes the unscoped (tenancy-off) path. A
        // missing key, empty/whitespace string, non-string, or malformed value all THROW — none can
        // silently select the unscoped read of the tenant-owned content_types. schemaFor() below reads
        // content_types, so a tenant-bearing job MUST run inside its tenant context.
        if (!array_key_exists('tenant_uuid', $data)) {
            throw new \InvalidArgumentException('EnsureFilterIndexesJob: tenant_uuid is required.');
        }
        $tenantUuid = $data['tenant_uuid'];

        if ($tenantUuid === null) {
            $enforcementActive = $container->get(SystemFlags::class)->enforcementActive();
            if ($enforcementActive) {
                throw new \RuntimeException(
                    'EnsureFilterIndexesJob: null tenant_uuid is invalid while tenancy enforcement is active.',
                );
            }
            $reconcile(); // ONLY an explicit null is the tenancy-off payload
            return;
        }
        if (!is_string($tenantUuid) || preg_match('/\A[0-9A-Za-z]{12}\z/', $tenantUuid) !== 1) {
            throw new \InvalidArgumentException('EnsureFilterIndexesJob: invalid tenant_uuid.');
        }
        $enforcementActive = $container->get(SystemFlags::class)->enforcementActive();
        if (!$enforcementActive) {
            throw new \RuntimeException(
                'EnsureFilterIndexesJob: tenant-bearing job cannot run while tenancy enforcement is inactive.',
            );
        }
        if (!$container->has(TenantContextRunner::class)) {
            throw new \RuntimeException('EnsureFilterIndexesJob: tenant runner unavailable for a tenant-bearing job.');
        }
        $container->get(TenantContextRunner::class)->runAsTenant($tenantUuid, $reconcile);
    }

    /**
     * @param LoggerInterface|null $logger
     */
    public function reconcile(
        Connection $db,
        ContentTypeRepository $types,
        string $typeUuid,
        ?LoggerInterface $logger = null,
        ?WriteBarrier $barrier = null
    ): void {
        $schema = $types->schemaFor($typeUuid);
        $desired = (new FilterIndexPlanner())->desiredIndexes($schema, $typeUuid);
        $desiredByName = [];
        foreach ($desired as $d) {
            $desiredByName[$d['index_name']] = $d;
        }

        $existing = $db->table('filter_indexes')
            ->where('content_type_uuid', '=', $typeUuid)
            ->get();
        $existingByName = [];
        foreach ($existing as $row) {
            $existingByName[(string) $row['index_name']] = $row;
        }

        // Create / ensure desired indexes.
        foreach ($desired as $d) {
            $this->assertSafeName($d['index_name']);
            $current = $existingByName[$d['index_name']] ?? null;
            $stale = $current !== null
                && ($current['status'] ?? '') === 'ready'
                && (string) ($current['filter_type'] ?? '') !== $d['filter_type'];
            if ($stale) {
                // Family changed (e.g. scalar 'number' → membership 'reference'); the index name is
                // stable, so drop the old physical index before recreating with the new definition.
                $this->dropIndex($db, $d['index_name'], $logger, $barrier);
            }
            if ($current === null || ($current['status'] ?? '') !== 'ready' || $stale) {
                $this->createIndex($db, $typeUuid, $d, $logger, $barrier);
            }
        }

        // Drop indexes that are no longer desired.
        foreach ($existingByName as $name => $row) {
            if (isset($desiredByName[$name])) {
                continue;
            }
            $this->assertSafeName($name);
            $this->dropIndex($db, (string) $name, $logger, $barrier);
            $write = fn (): int => $db->table('filter_indexes')
                ->where('content_type_uuid', '=', $typeUuid)
                ->where('index_name', '=', $name)
                ->delete();
            $barrier !== null ? $barrier->runWritable($write) : $write();
        }
    }

    /**
     * @param array{field:string,filter_type:string,index_name:string,expression:string} $d
     */
    private function createIndex(
        Connection $db,
        string $typeUuid,
        array $d,
        ?LoggerInterface $logger,
        ?WriteBarrier $barrier = null
    ): void {
        $name = $d['index_name'];
        $this->upsertRegistry($db, $typeUuid, $d, 'pending', $barrier);

        $method = isset($d['method']) && $d['method'] === 'gin' ? 'gin' : 'btree';
        $using = $method === 'gin' ? ' USING gin' : '';
        $sql = sprintf(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS %s ON %s%s (%s)',
            $name,
            self::TABLE,
            $using,
            $d['expression']
        );
        try {
            // A prior CONCURRENTLY build that failed/was interrupted leaves an INVALID index of this
            // name behind (Postgres). `CREATE INDEX ... IF NOT EXISTS` would then silently no-op over
            // it (no error), so the fresh build never happens — drop the dead one first so the create
            // actually rebuilds. Postgres-only; other drivers keep their existing behaviour.
            if ($this->pgIndexValidity($db, $name) === false) {
                $this->dropIndex($db, $name, $logger, $barrier);
            }

            // Raw DDL on entry_versions bypasses QueryExecutor (the interceptor), so honor the barrier
            // explicitly, immediately before the CREATE, to minimise the check-to-execute window.
            // CREATE INDEX CONCURRENTLY cannot run inside a transaction.
            $write = fn (): int|false => $db->getPDO()->exec($sql);
            $barrier !== null ? $barrier->runWritable($write) : $write();

            // Not throwing is NOT proof of a usable index: the IF NOT EXISTS path can skip over an
            // invalid index, and CONCURRENTLY can leave one invalid. On Postgres, confirm the index
            // is actually valid before marking it ready; if it is invalid, drop it and mark failed so
            // a later reconcile rebuilds instead of the planner silently seq-scanning over a dead one.
            if ($this->pgIndexValidity($db, $name) === false) {
                $this->dropIndex($db, $name, $logger, $barrier);
                $this->markStatus($db, $typeUuid, $name, 'failed', $barrier);
                $logger?->warning('EnsureFilterIndexesJob: expression index built invalid', [
                    'index' => $name,
                    'content_type_uuid' => $typeUuid,
                ]);
                return;
            }

            $this->markStatus($db, $typeUuid, $name, 'ready', $barrier);
        } catch (\Throwable $e) {
            $this->markStatus($db, $typeUuid, $name, 'failed', $barrier);
            $logger?->warning('EnsureFilterIndexesJob: failed to create expression index', [
                'index' => $name,
                'content_type_uuid' => $typeUuid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Validity of a same-named index on Postgres: true = present and valid, false = present but
     * invalid, null = not present (or not Postgres, where this check does not apply).
     */
    private function pgIndexValidity(Connection $db, string $name): ?bool
    {
        $pdo = $db->getPDO();
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            return null;
        }

        // Cast to int and fetch a row (not fetchColumn): fetchColumn() returns false both for "no
        // row" and for a boolean-false value, which would make an INVALID index look absent.
        $stmt = $pdo->prepare(
            'SELECT i.indisvalid::int
             FROM pg_class c
             JOIN pg_index i ON i.indexrelid = c.oid
             WHERE c.relname = :name'
        );
        $stmt->execute([':name' => $name]);
        $row = $stmt->fetch(\PDO::FETCH_NUM);
        if ($row === false) {
            return null; // no such index
        }

        return (int) $row[0] === 1;
    }

    private function dropIndex(
        Connection $db,
        string $name,
        ?LoggerInterface $logger,
        ?WriteBarrier $barrier = null
    ): void {
        $sql = sprintf('DROP INDEX CONCURRENTLY IF EXISTS %s', $name);
        try {
            $write = fn (): int|false => $db->getPDO()->exec($sql);
            $barrier !== null ? $barrier->runWritable($write) : $write();
        } catch (\Throwable $e) {
            $logger?->warning('EnsureFilterIndexesJob: failed to drop expression index', [
                'index' => $name,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array{field:string,filter_type:string,index_name:string,expression:string} $d
     */
    private function upsertRegistry(
        Connection $db,
        string $typeUuid,
        array $d,
        string $status,
        ?WriteBarrier $barrier = null
    ): void {
        $write = function () use ($db, $typeUuid, $d, $status): void {
            $exists = $db->table('filter_indexes')
                ->where('content_type_uuid', '=', $typeUuid)
                ->where('field', '=', $d['field'])
                ->first();
            if ($exists === null) {
                $db->table('filter_indexes')->insert([
                    'uuid' => Utils::generateNanoID(12),
                    'content_type_uuid' => $typeUuid,
                    'field' => $d['field'],
                    'filter_type' => $d['filter_type'],
                    'index_name' => $d['index_name'],
                    'status' => $status,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                return;
            }
            $db->table('filter_indexes')
                ->where('content_type_uuid', '=', $typeUuid)
                ->where('field', '=', $d['field'])
                ->update([
                    'filter_type' => $d['filter_type'],
                    'index_name' => $d['index_name'],
                    'status' => $status,
                ]);
        };
        $barrier !== null ? $barrier->runWritable($write) : $write();
    }

    private function markStatus(
        Connection $db,
        string $typeUuid,
        string $name,
        string $status,
        ?WriteBarrier $barrier = null
    ): void {
        $write = fn (): int => $db->table('filter_indexes')
            ->where('content_type_uuid', '=', $typeUuid)
            ->where('index_name', '=', $name)
            ->update(['status' => $status]);
        $barrier !== null ? $barrier->runWritable($write) : $write();
    }

    private function assertSafeName(string $name): void
    {
        if (preg_match('/\A[a-z0-9_]+\z/', $name) !== 1) {
            throw new \InvalidArgumentException("unsafe index name: '{$name}'");
        }
    }
}
