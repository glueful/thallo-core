<?php

declare(strict_types=1);

namespace Thallo\Core\Payments\Tenancy;

use Glueful\Database\Connection;
use PDO;

/**
 * Moves payments' unassigned rows (tenant '') into the default workspace — once, atomically with
 * the schema flip that points payments there ({@see PaymentAdoptionContributor}), and again on
 * demand for sites enabled before that existed (`thallo:tenancy:payments:repair`).
 *
 * {@see diagnose()} writes nothing. {@see apply()} runs in one transaction: it locks every payments
 * table against writers, diagnoses again under the lock, refuses the whole move on any collision
 * or ambiguity, moves ONLY tenant '' rows (ids, uuids and every relationship column unchanged; a
 * row some workspace already owns is never touched), then adds a CHECK so the database itself
 * refuses an unassigned row from then on. Applying twice moves nothing the second time.
 *
 * Raw PDO on purpose: this is maintenance across every workspace, so it must not pass through the
 * per-request tenant query guard — the same reason the schema retrofit uses raw PDO.
 */
final class PaymentTenancyAdoption
{
    public const CONSTRAINT = 'thallo_tenant_assigned';

    /** Statuses of an intent that is live or collected: two of them for one order is a duplicate. */
    private const COUNTED_INTENT_STATUSES = ['initializing', 'open', 'closed'];

    /**
     * Related rows that place an unassigned row in a workspace: [table, column, related table,
     * related column, extra condition on the row, reason].
     */
    private const OWNERSHIP = [
        ['payments', 'payable_id', 'commerce_orders', 'uuid', "u.payable_type = 'commerce_order'",
            'its order belongs to another workspace'],
        ['payment_intents', 'payable_id', 'commerce_orders', 'uuid', "u.payable_type = 'commerce_order'",
            'its order belongs to another workspace'],
        ['invoices', 'payable_id', 'commerce_orders', 'uuid', "u.payable_type = 'commerce_order'",
            'its order belongs to another workspace'],
        ['invoices', 'billing_plan_uuid', 'billing_plans', 'uuid', null,
            'its billing plan belongs to another workspace'],
        ['gateway_subscriptions', 'billing_plan_uuid', 'billing_plans', 'uuid', null,
            'its billing plan belongs to another workspace'],
        ['payments', 'reference', 'payment_intents', 'reference', 'r.gateway = u.gateway',
            'its payment intent belongs to another workspace'],
        ['payment_intents', 'reference', 'payments', 'reference', 'r.gateway = u.gateway',
            'its payment belongs to another workspace'],
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return list<string> payments' workspace-owned tables present in this database */
    public function tables(): array
    {
        return array_values(array_filter(
            PaymentTables::workspaceOwned(),
            fn (string $table): bool => $this->exists($table),
        ));
    }

    public function diagnose(string $tenantUuid): PaymentAdoptionReport
    {
        self::assertTenant($tenantUuid);
        $counts = [];
        $collisions = [];
        foreach ($this->tables() as $table) {
            $counts[$table] = $this->counts($table, $tenantUuid);
            if ($counts[$table]['unassigned'] > 0) {
                array_push($collisions, ...$this->collisions($table, $tenantUuid));
            }
        }

        return new PaymentAdoptionReport(
            $tenantUuid,
            $counts,
            $collisions,
            $this->ambiguous($tenantUuid),
            $this->duplicates($tenantUuid),
            $this->constrained(),
        );
    }

    /** @throws PaymentAdoptionRefusedException with nothing written */
    public function apply(string $tenantUuid, int $lockTimeoutMs = 10000): PaymentAdoptionReport
    {
        self::assertTenant($tenantUuid);
        $tables = $this->tables();
        if ($tables === []) {
            return $this->diagnose($tenantUuid);
        }

        $move = function () use ($tenantUuid, $tables, $lockTimeoutMs): PaymentAdoptionReport {
            $pdo = $this->pdo();
            $pdo->exec('SET LOCAL lock_timeout = ' . max(1, $lockTimeoutMs));
            $pdo->exec(
                'LOCK TABLE ' . implode(', ', array_map(self::ident(...), $tables)) . ' IN SHARE ROW EXCLUSIVE MODE'
            );

            $report = $this->diagnose($tenantUuid);
            if ($report->refused()) {
                throw new PaymentAdoptionRefusedException($report);
            }

            $moved = [];
            foreach ($tables as $table) {
                $update = $pdo->prepare(
                    'UPDATE ' . self::ident($table) . " SET tenant_uuid = ? WHERE tenant_uuid = ''"
                );
                $update->execute([$tenantUuid]);
                $moved[$table] = $update->rowCount();
            }
            foreach (array_diff($tables, $report->constrained) as $table) {
                $pdo->exec('ALTER TABLE ' . self::ident($table) . ' ADD CONSTRAINT ' . self::CONSTRAINT
                    . " CHECK (tenant_uuid <> '')");
            }

            return $report->withMoved($moved, $tables);
        };

        return $this->connection->transaction($move);
    }

    /** @return array{unassigned: int, default: int, other: int} */
    private function counts(string $table, string $tenantUuid): array
    {
        $row = $this->fetchAll(
            "SELECT count(*) FILTER (WHERE tenant_uuid = '') AS unassigned, "
            . 'count(*) FILTER (WHERE tenant_uuid = ?) AS "default", '
            . "count(*) FILTER (WHERE tenant_uuid NOT IN ('', ?)) AS other FROM " . self::ident($table),
            [$tenantUuid, $tenantUuid],
        )[0];

        return [
            'unassigned' => (int) $row['unassigned'],
            'default' => (int) $row['default'],
            'other' => (int) $row['other'],
        ];
    }

    /** Unassigned rows whose workspace-scoped unique key the default workspace already holds. */
    private function collisions(string $table, string $tenantUuid): array
    {
        $found = [];
        foreach ($this->workspaceUniqueKeys($table) as $index => $columns) {
            $join = implode(' AND ', array_map(
                static fn (string $c): string => 'd.' . self::ident($c) . ' = u.' . self::ident($c),
                $columns,
            ));
            $select = implode(', ', array_map(static fn (string $c): string => 'u.' . self::ident($c), $columns));
            $rows = $this->fetchAll(
                "SELECT u.id AS unassigned_id, d.id AS existing_id, {$select} FROM " . self::ident($table) . ' u JOIN '
                . self::ident($table) . " d ON d.tenant_uuid = ? AND {$join} WHERE u.tenant_uuid = '' ORDER BY u.id",
                [$tenantUuid],
            );
            foreach ($rows as $row) {
                $found[] = [
                    'table' => $table,
                    'index' => $index,
                    'key' => array_intersect_key($row, array_flip($columns)),
                    'unassigned_id' => (int) $row['unassigned_id'],
                    'existing_id' => (int) $row['existing_id'],
                ];
            }
        }

        return $found;
    }

    /**
     * Unique indexes that include tenant_uuid, read from the catalog so a Payvia upgrade that adds
     * one is covered without a change here.
     *
     * @return array<string, list<string>> index name => its other columns, in index order
     */
    private function workspaceUniqueKeys(string $table): array
    {
        $rows = $this->fetchAll(
            'SELECT i.relname AS index_name, a.attname AS column_name FROM pg_index x '
            . 'JOIN pg_class t ON t.oid = x.indrelid JOIN pg_class i ON i.oid = x.indexrelid '
            . 'JOIN LATERAL unnest(x.indkey) WITH ORDINALITY AS k(attnum, ord) ON true '
            . 'JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = k.attnum '
            . 'WHERE x.indisunique AND t.oid = to_regclass(?) ORDER BY i.relname, k.ord',
            [$table],
        );
        $indexes = [];
        foreach ($rows as $row) {
            $indexes[(string) $row['index_name']][] = (string) $row['column_name'];
        }

        $keys = [];
        foreach ($indexes as $index => $columns) {
            $others = array_values(array_diff($columns, ['tenant_uuid']));
            if ($others !== [] && count($others) < count($columns)) {
                $keys[$index] = $others;
            }
        }

        return $keys;
    }

    private function ambiguous(string $tenantUuid): array
    {
        $found = [];
        foreach (self::OWNERSHIP as [$table, $column, $related, $relatedColumn, $condition, $reason]) {
            if (!$this->exists($table) || !$this->exists($related)) {
                continue;
            }
            $rows = $this->fetchAll(
                'SELECT u.id, u.uuid, r.tenant_uuid AS workspace FROM ' . self::ident($table) . ' u JOIN '
                . self::ident($related) . ' r ON r.' . self::ident($relatedColumn) . ' = u.' . self::ident($column)
                . " WHERE u.tenant_uuid = '' AND r.tenant_uuid NOT IN ('', ?)"
                . ($condition === null ? '' : " AND {$condition}") . ' ORDER BY u.id',
                [$tenantUuid],
            );
            foreach ($rows as $row) {
                $found[$table . '#' . $row['id']] ??= [
                    'table' => $table,
                    'id' => (int) $row['id'],
                    'uuid' => (string) $row['uuid'],
                    'workspace' => (string) $row['workspace'],
                    'reason' => $reason,
                ];
            }
        }

        return array_values($found);
    }

    /**
     * Orders with more than one live or collected intent across the unassigned rows and the default
     * workspace's — what a checkout that could not see its first intent left behind.
     */
    private function duplicates(string $tenantUuid): array
    {
        if (!$this->exists('payment_intents')) {
            return [];
        }
        $statuses = implode(', ', array_map(static fn (string $s): string => "'{$s}'", self::COUNTED_INTENT_STATUSES));
        $groups = $this->fetchAll(
            'SELECT payable_type, payable_id FROM payment_intents '
            . "WHERE tenant_uuid IN ('', ?) AND status IN ({$statuses}) "
            . 'GROUP BY payable_type, payable_id HAVING count(*) > 1 ORDER BY min(id)',
            [$tenantUuid],
        );

        $found = [];
        foreach ($groups as $group) {
            $intents = $this->fetchAll(
                'SELECT uuid, tenant_uuid, status, gateway, reference, amount, currency, created_at '
                . "FROM payment_intents WHERE tenant_uuid IN ('', ?) AND payable_type = ? AND payable_id = ? "
                . "AND status IN ({$statuses}) ORDER BY id",
                [$tenantUuid, $group['payable_type'], $group['payable_id']],
            );
            $settled = $this->exists('payments') ? (int) $this->fetchAll(
                "SELECT count(*) AS n FROM payments WHERE tenant_uuid IN ('', ?) AND payable_type = ? "
                . "AND payable_id = ? AND status = 'success'",
                [$tenantUuid, $group['payable_type'], $group['payable_id']],
            )[0]['n'] : 0;
            $found[] = [
                'payable_type' => (string) $group['payable_type'],
                'payable_id' => (string) $group['payable_id'],
                'workspace' => $tenantUuid,
                'intents' => $intents,
                'settled_payments' => $settled,
            ];
        }

        return $found;
    }

    /** @return list<string> tables that already refuse an unassigned row */
    private function constrained(): array
    {
        $rows = $this->fetchAll(
            'SELECT t.relname FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid '
            . 'WHERE c.conname = ? AND t.relnamespace = to_regnamespace(current_schema()) ORDER BY t.relname',
            [self::CONSTRAINT],
        );
        $present = array_map(static fn (array $row): string => (string) $row['relname'], $rows);

        return array_values(array_intersect($this->tables(), $present));
    }

    private function exists(string $table): bool
    {
        return $this->fetchAll('SELECT to_regclass(?) IS NOT NULL AS present', [$table])[0]['present'] === true;
    }

    /** @return list<array<string, mixed>> */
    private function fetchAll(string $sql, array $bindings = []): array
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($bindings);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function pdo(): PDO
    {
        return $this->connection->getPDO();
    }

    private static function ident(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    private static function assertTenant(string $tenantUuid): void
    {
        if (trim($tenantUuid) === '') {
            throw new \InvalidArgumentException('A default workspace is required.');
        }
    }
}
