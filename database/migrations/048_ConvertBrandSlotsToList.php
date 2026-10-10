<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Converts the revision-4 build's fixed brand keys (`theme_brand_1` … `theme_brand_3`, each
 * `{"name","hex"}`) into the brand colour list (`theme_brand_colors`, custom palette spec §2.3, §7),
 * keeping ids 1–3, and deletes them. That build never shipped: the keys exist only on development
 * installs. Its Clear deleted a slot's row, so a cleared slot leaves no setting behind while content
 * may still name it: on every workspace that used the palette — an old key, a palette_state row or a
 * palette_jobs row — each id 1–3 not configured is reserved as removed, named from its last audit
 * entry where the audit log can say whose it was (a single-site install), else "Brand N". A fresh
 * install reserves nothing. A list already present is never overwritten.
 */
final class ConvertBrandSlotsToList implements MigrationInterface
{
    private const OLD = ['theme_brand_1' => 1, 'theme_brand_2' => 2, 'theme_brand_3' => 3];

    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('settings')) {
            return;
        }
        $pdo = $schema->getConnection()->getPDO();
        $tenanted = $schema->hasColumn('settings', 'tenant_uuid');
        $tenantOf = static fn (string $table): string => $schema->hasColumn($table, 'tenant_uuid')
            ? 'tenant_uuid'
            : 'NULL AS tenant_uuid';

        /** @var array<string, array{tenant: ?string, keys: array<string,string>}> $groups */
        $groups = [];
        $add = static function (?string $tenant) use (&$groups): void {
            $groups[$tenant ?? ''] ??= ['tenant' => $tenant, 'keys' => []];
        };
        $rows = $pdo->query(
            'SELECT ' . $tenantOf('settings') . ', key, value FROM settings '
            . "WHERE key IN ('theme_brand_1', 'theme_brand_2', 'theme_brand_3', 'theme_brand_colors')",
        )->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $tenant = $row['tenant_uuid'] === null ? null : (string) $row['tenant_uuid'];
            $add($tenant);
            $groups[$tenant ?? '']['keys'][(string) $row['key']] = (string) $row['value'];
        }
        foreach (['palette_state', 'palette_jobs'] as $table) {
            if (!$schema->hasTable($table)) {
                continue;
            }
            $owners = $pdo->query('SELECT DISTINCT ' . $tenantOf($table) . " FROM {$table}")
                ->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($owners as $row) {
                $add($row['tenant_uuid'] === null ? null : (string) $row['tenant_uuid']);
            }
        }

        // The audit log carries no workspace: its names are trusted only on a single-site install.
        $audited = [];
        if (!$tenanted && $schema->hasTable('audit_logs')) {
            $names = $pdo->query(
                "SELECT target_uuid, target_label FROM audit_logs WHERE target_type = 'palette_slot' "
                . "AND target_uuid IN ('brand-1', 'brand-2', 'brand-3') AND target_label IS NOT NULL "
                . 'ORDER BY occurred_at, id',
            )->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($names as $row) {
                $id = (int) substr((string) $row['target_uuid'], 6);
                $audited[$id] = mb_substr(trim((string) $row['target_label']), 0, 32);
            }
        }

        foreach ($groups as $group) {
            $keys = $group['keys'];
            $where = $tenanted ? ($group['tenant'] === null ? 'tenant_uuid IS NULL' : 'tenant_uuid = :t') : 'TRUE';
            $bind = $tenanted && $group['tenant'] !== null ? ['t' => $group['tenant']] : [];
            if (!isset($keys['theme_brand_colors'])) {
                $colors = [];
                $removed = [];
                foreach (self::OLD as $key => $id) {
                    $data = json_decode($keys[$key] ?? '', true);
                    $name = is_array($data) && is_string($data['name'] ?? null) ? trim($data['name']) : '';
                    $hex = is_array($data) && is_string($data['hex'] ?? null) ? strtolower(trim($data['hex'])) : '';
                    if ($name !== '' && preg_match('/\A#[0-9a-f]{6}\z/', $hex) === 1) {
                        $colors[] = ['id' => $id, 'name' => mb_substr($name, 0, 32), 'hex' => $hex];
                    } else {
                        $name = ($audited[$id] ?? '') !== '' ? $audited[$id] : "Brand {$id}";
                        $removed[] = ['id' => $id, 'name' => $name];
                    }
                }
                $insert = $pdo->prepare(
                    'INSERT INTO settings (' . ($tenanted ? 'tenant_uuid, ' : '') . 'key, value, updated_at) VALUES ('
                    . ($tenanted ? ':tenant, ' : '') . "'theme_brand_colors', :value, CURRENT_TIMESTAMP)",
                );
                $insert->execute(($tenanted ? ['tenant' => $group['tenant']] : [])
                    + ['value' => json_encode(['revision' => 0, 'colors' => $colors, 'removed' => $removed])]);
            }
            $delete = $pdo->prepare(
                "DELETE FROM settings WHERE {$where} AND key IN ('theme_brand_1', 'theme_brand_2', 'theme_brand_3')",
            );
            $delete->execute($bind);
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        // Nothing: the revision-4 keys never shipped.
    }

    public function getDescription(): string
    {
        return 'Brand colours: the three fixed keys become the brand colour list (ids 1–3 kept or reserved)';
    }
}
