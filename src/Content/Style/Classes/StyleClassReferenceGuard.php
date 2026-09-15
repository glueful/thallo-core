<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Style\Classes;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\TenantScope;
use Thallo\Contracts\Style\BlockStyleRegistry;
use Thallo\Contracts\Tenancy\WriteBarrier;
use Thallo\Core\Content\Schema\ContentTypeSchema;

/**
 * The serialisation between a document write and the everywhere-jobs (visual builder spec
 * §4.5). Inside the document's write transaction, for every style class reference the write
 * INTRODUCES — present in the new document, absent from the stored one — the guard runs
 * `UPDATE style_classes SET reference_guard = reference_guard + 1 WHERE id = ? AND locked_by_job
 * IS NULL [AND archived_at IS NULL]`: a write on the row a job's lock acquisition also writes,
 * so every engine orders the two. Zero affected rows is a locked class (409) or an archived one
 * (422). References already stored are never re-checked, and a trusted reference — one a
 * restored revision already carried — may name an archived class but never a locked one.
 * `reference_guard` is a lock token: excluded from snapshots and from what the generation names.
 */
final class StyleClassReferenceGuard
{
    public function __construct(
        private readonly Connection $db,
        private readonly BlockStyleRegistry $registry,
        private readonly ?ApplicationContext $context = null,
        private readonly ?CurrentTenantResolver $tenants = null,
        private readonly ?WriteBarrier $barrier = null,
    ) {
    }

    /**
     * @param array<string,mixed> $before the stored document's fields
     * @param array<string,mixed> $after the document being written
     * @param list<string> $trusted references a restored revision already carried
     */
    public function assertWritable(array $before, array $after, ContentTypeSchema $schema, array $trusted = []): void
    {
        $this->check($this->referencesIn($before, $schema), $this->referencesIn($after, $schema), $trusted);
    }

    /**
     * @param list<array<string,mixed>> $before
     * @param list<array<string,mixed>> $after
     */
    public function assertBlocksWritable(array $before, array $after): void
    {
        $this->check($this->referencesInBlocks($before), $this->referencesInBlocks($after), []);
    }

    /**
     * @param list<string> $before
     * @param list<string> $after
     * @param list<string> $trusted
     */
    private function check(array $before, array $after, array $trusted): void
    {
        foreach (array_values(array_unique(array_diff($after, $before))) as $id) {
            $this->guard($id, in_array($id, $trusted, true));
        }
    }

    private function guard(string $id, bool $trusted): void
    {
        $tenant = TenantScope::current($this->tenants, $this->context);
        $sql = 'UPDATE style_classes SET reference_guard = reference_guard + 1'
            . ' WHERE id = :id AND locked_by_job IS NULL'
            . ($trusted ? '' : ' AND archived_at IS NULL')
            . ($tenant === null ? '' : ' AND tenant_uuid = :tenant');
        $params = ['id' => $id];
        if ($tenant !== null) {
            $params['tenant'] = $tenant;
        }
        $write = function () use ($sql, $params): int {
            $stmt = $this->db->getPDO()->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        };
        $affected = (int) ($this->barrier !== null ? $this->barrier->runWritable($write) : $write());
        if ($affected >= 1) {
            return;
        }
        $row = $this->db->table('style_classes')
            ->select(['locked_by_job', 'archived_at'])
            ->where('id', '=', $id)
            ->first();
        if ($row === null) {
            return; // ownership is the validator's concern; nothing to serialise against
        }
        if (($row['locked_by_job'] ?? null) !== null) {
            throw new StyleClassLocked($id, (string) $row['locked_by_job']);
        }
        throw new StyleClassArchived($id);
    }

    /**
     * The references a document carries, in document order (duplicates kept).
     *
     * @param array<string,mixed> $fields
     * @return list<string>
     */
    public function referencesOf(array $fields, ContentTypeSchema $schema): array
    {
        return $this->referencesIn($fields, $schema);
    }

    /**
     * @param array<string,mixed> $fields
     * @return list<string>
     */
    private function referencesIn(array $fields, ContentTypeSchema $schema): array
    {
        $out = [];
        foreach ($schema->fields() as $field) {
            if ($field->type === 'blocks') {
                $list = $fields[$field->name] ?? null;
                $out = array_merge($out, $this->referencesInBlocks(is_array($list) ? $list : []));
            }
        }
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $blocks
     * @return list<string>
     */
    private function referencesInBlocks(array $blocks): array
    {
        $out = [];
        foreach ($blocks as $block) {
            if (!is_array($block) || !is_string($block['type'] ?? null)) {
                continue;
            }
            $classes = $block['settings']['classes'] ?? null;
            if (is_array($classes)) {
                foreach ($classes as $id) {
                    if (is_string($id)) {
                        $out[] = $id;
                    }
                }
            }
            foreach ($this->registry->regionsFor($block['type']) as $slot) {
                $child = $block['data'][$slot] ?? null;
                $out = array_merge($out, $this->referencesInBlocks(is_array($child) ? $child : []));
            }
        }
        return $out;
    }
}
