<?php

declare(strict_types=1);

namespace Thallo\Core\Settings;

use Glueful\Bootstrap\ApplicationContext;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Platform-payments-settings spec (Task 3): the physical-table mechanics of the TEMPORARY
 * read-only compatibility path over the OLD tenant `settings` table — owned here so it can be
 * shared, unchanged, by {@see LegacyPlatformPaymentSettingsReader} (Task 4's fallback until a
 * migration marker is written) AND Task 5's migration command (raw-row enumeration/verification/
 * pruning).
 *
 * Two schema eras coexist across installs, told apart by SCHEMA INTROSPECTION alone (the real
 * schema builder's hasColumn(), never a config flag or version number):
 *  - PRE-RETROFIT: `key, value, updated_at` — no `tenant_uuid` column. One unscoped row per key
 *    is the whole table's answer for that key; there is no "other tenant" concept.
 *  - POST-RETROFIT: `tenant_uuid, key, value, updated_at` — composite key, one row PER TENANT per
 *    key. The candidate row for a key is whichever one belongs to the persisted default
 *    workspace ({@see SystemFlags::defaultTenantUuid()} — the exact pointer SingleStoreTenant
 *    trusts); every row for any OTHER tenant is a conflict, never a candidate. With no default
 *    pointer resolved yet, there is no candidate at all (every row is unclaimed).
 *
 * Every query below is a DIRECT query against $table through the connection's query builder —
 * never `SettingsStore`, never `runAsTenant()`, never any current-tenant helper. That is the
 * entire reason this class exists distinct from the app's normal settings stores: it must read
 * the exact bytes on disk for the PERSISTED default workspace, regardless of which tenant (if
 * any) happens to be "current" for the request/process doing the reading or migrating.
 *
 * $table defaults to the real `settings` table (production wiring) but is constructor-injectable
 * and validated as a safe SQL identifier SOLELY so tests can point every query here at an
 * isolated temporary table (created and dropped by the real schema builder) — the shared
 * production table must never be altered by a test.
 */
final class LegacyPlatformPaymentSettingsRepository
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly SystemFlags $flags,
        private readonly string $table = 'settings',
    ) {
        self::assertSafeIdentifier($this->table);
    }

    /**
     * The one candidate row for $key under this install's schema era — null when the table
     * doesn't exist, no row exists, or (post-retrofit only) no default workspace pointer has
     * been persisted yet.
     *
     * @return array{key:string,tenant_uuid:?string,stored_value:string}|null
     */
    public function candidateRaw(string $key): ?array
    {
        $schema = db($this->context)->getSchemaBuilder();
        if (!$schema->hasTable($this->table)) {
            return null;
        }

        if (!$schema->hasColumn($this->table, 'tenant_uuid')) {
            $row = db($this->context)->table($this->table)
                ->select(['key', 'value'])
                ->where(['key' => $key])
                ->first();
            if ($row === null) {
                return null;
            }

            return ['key' => $key, 'tenant_uuid' => null, 'stored_value' => (string) ($row['value'] ?? '')];
        }

        $default = $this->flags->defaultTenantUuid();
        if ($default === null) {
            return null;
        }

        $row = db($this->context)->table($this->table)
            ->select(['tenant_uuid', 'key', 'value'])
            ->where(['key' => $key, 'tenant_uuid' => $default])
            ->first();
        if ($row === null) {
            return null;
        }

        return ['key' => $key, 'tenant_uuid' => $default, 'stored_value' => (string) ($row['value'] ?? '')];
    }

    /**
     * TRUE when this table is POST-RETROFIT (tenant-scoped) but NO default workspace pointer has
     * been persisted — the state in which {@see candidateRaw()} can claim nothing and, precisely
     * because nothing is claimed, {@see conflictRowsForPrefix()} reports EVERY row (this
     * installation's own included) as "some other workspace's".
     *
     * Both of those behaviours are correct in isolation and are deliberately UNCHANGED by this
     * accessor: it adds no candidate, hides no conflict, and reads nothing but the schema shape and
     * the pointer. It exists because the COMBINATION is indistinguishable, at the row level, from a
     * genuine cross-workspace conflict — and the two want opposite responses. Task 5's migration
     * command uses it to refuse outright rather than let an operator read "every one of my own rows
     * belongs to another workspace" and acknowledge them away onto an empty platform store. The
     * real remedy is establishing the default workspace (`thallo:tenancy:single-store:repair`),
     * which is also what {@see \Thallo\Tenancy\SingleStoreTenant} demands in this same state.
     */
    public function awaitsDefaultWorkspacePointer(): bool
    {
        $schema = db($this->context)->getSchemaBuilder();
        if (!$schema->hasTable($this->table) || !$schema->hasColumn($this->table, 'tenant_uuid')) {
            return false;
        }

        return $this->flags->defaultTenantUuid() === null;
    }

    /**
     * Every row for $key belonging to a tenant OTHER than the resolved candidate (or, with no
     * default pointer resolved, every row at all — none of them is claimed). Raw shape only;
     * {@see LegacyPlatformPaymentSettingsReader::conflicts()} strips `stored_value` before this
     * ever reaches a caller. Pre-retrofit tables have no tenant scoping, so there is no "other
     * tenant" concept there: always [].
     *
     * @return list<array{tenant_uuid:string,key:string,stored_value:string}>
     */
    public function conflictRows(string $key): array
    {
        $schema = db($this->context)->getSchemaBuilder();
        if (!$schema->hasTable($this->table) || !$schema->hasColumn($this->table, 'tenant_uuid')) {
            return [];
        }

        $default = $this->flags->defaultTenantUuid();
        $rows = db($this->context)->table($this->table)
            ->select(['tenant_uuid', 'key', 'value'])
            ->where(['key' => $key])
            ->get();

        return $this->excludeCandidateRows($rows, $default);
    }

    /**
     * Every OTHER-than-candidate row for every key whose name starts with $prefix — ONE SQL query
     * (a `LIKE '$prefix%'` predicate, projecting only tenant_uuid/key/value) rather than an
     * unbounded per-key scan. Used by {@see LegacyPlatformPaymentSettingsReader::conflicts()} to
     * scope its sanitized diagnostic to the keys it actually owns (`payvia.*`) instead of
     * surfacing every unrelated multi-tenant setting (theme, notification prefs, ...) in the
     * table as a false "conflict". Always [] on a pre-retrofit table (no tenant scoping there).
     *
     * @return list<array{tenant_uuid:string,key:string,stored_value:string}>
     */
    public function conflictRowsForPrefix(string $prefix): array
    {
        $schema = db($this->context)->getSchemaBuilder();
        if (!$schema->hasTable($this->table) || !$schema->hasColumn($this->table, 'tenant_uuid')) {
            return [];
        }

        $default = $this->flags->defaultTenantUuid();
        $rows = db($this->context)->table($this->table)
            ->select(['tenant_uuid', 'key', 'value'])
            ->whereLike('key', $prefix . '%')
            ->get();

        return $this->excludeCandidateRows($rows, $default);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array{tenant_uuid:string,key:string,stored_value:string}>
     */
    private function excludeCandidateRows(array $rows, ?string $default): array
    {
        $out = [];
        foreach ($rows as $row) {
            $tenantUuid = (string) ($row['tenant_uuid'] ?? '');
            if ($default !== null && $tenantUuid === $default) {
                continue; // the resolved candidate for its key — not a conflict
            }

            $out[] = [
                'tenant_uuid' => $tenantUuid,
                'key' => (string) ($row['key'] ?? ''),
                'stored_value' => (string) ($row['value'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Compare-and-delete: removes ONLY the row matching the schema-shape-correct locator
     * (post-retrofit: key + $tenantUuid; pre-retrofit: key alone — $tenantUuid must be null)
     * AND whose stored bytes still equal $expectedStoredValue. A locator that doesn't match this
     * table's real shape, a tenant that no longer holds the row, or a value that changed since it
     * was read/verified all affect 0 rows — and that is a LOUD failure, never a silent no-op:
     * verification must never delete a value it did not itself just inspect.
     */
    public function deleteExact(string $key, ?string $tenantUuid, string $expectedStoredValue): void
    {
        $schema = db($this->context)->getSchemaBuilder();
        $hasTenantColumn = $schema->hasTable($this->table) && $schema->hasColumn($this->table, 'tenant_uuid');

        if ($hasTenantColumn && $tenantUuid === null) {
            throw new \InvalidArgumentException(
                "Cannot delete [{$key}]: table [{$this->table}] is post-retrofit and requires a tenant_uuid locator."
            );
        }
        if (!$hasTenantColumn && $tenantUuid !== null) {
            throw new \InvalidArgumentException(
                "Cannot delete [{$key}] with a tenant_uuid locator: table [{$this->table}] is pre-retrofit "
                . "and has no tenant_uuid column."
            );
        }

        $where = ['key' => $key, 'value' => $expectedStoredValue];
        if ($hasTenantColumn) {
            $where['tenant_uuid'] = $tenantUuid;
        }

        $affected = db($this->context)->table($this->table)->where($where)->delete();
        if ($affected < 1) {
            throw new \RuntimeException(
                "deleteExact() affected 0 rows for [{$key}]: the row is missing, its tenant locator no "
                . "longer matches, or its stored value changed since it was verified."
            );
        }
    }

    private static function assertSafeIdentifier(string $name): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new \InvalidArgumentException("Unsafe table identifier for legacy settings repository: [{$name}].");
        }
    }
}
