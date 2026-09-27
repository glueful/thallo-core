<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Layouts;

use Glueful\Database\Connection;
use Thallo\Contracts\Layouts\LayoutSurfaceRegistry;
use Thallo\Core\Content\Schema\Migration\DeleteField;
use Thallo\Core\Content\Schema\Migration\MigrationOpSet;
use Thallo\Core\Content\Schema\Migration\RenameField;

/**
 * Layouts follow their content type (type layouts spec §5.7). A field a layout shows cannot be
 * deleted (the change is refused with the layouts named); renaming it rewrites the blocks that show
 * it, bumping each layout's version; deleting the type tombstones its layouts.
 *
 * Every write runs inside the caller's transaction, under the type's lock and then each layout's;
 * announcing each change ({@see LayoutChanges}) follows the outermost commit.
 */
final class LayoutBindings
{
    public function __construct(
        private readonly Connection $db,
        private readonly LayoutRepository $layouts,
        private readonly LayoutWriteLock $lock,
        private readonly LayoutSurfaceRegistry $surfaces,
        private readonly LayoutChanges $changes,
    ) {
    }

    /**
     * Refuse deleting a bound field and rewrite renamed ones: what a schema migration does to the
     * type's layouts before its flip.
     *
     * @throws LayoutBindingConflict
     */
    public function apply(string $typeSlug, MigrationOpSet $ops): void
    {
        $deleted = [];
        foreach ($ops->ops() as $op) {
            if ($op instanceof DeleteField) {
                $deleted[] = $op->name;
            }
        }
        $bound = $this->boundTo($typeSlug, $deleted);
        if ($bound !== []) {
            throw new LayoutBindingConflict($bound);
        }
        foreach ($ops->ops() as $op) {
            if ($op instanceof RenameField) {
                $this->renameField($typeSlug, $op->from, $op->to);
            }
        }
    }

    /**
     * @param list<string> $fields
     * @return array<string, list<string>> each field some live layout of the type shows => their labels
     */
    public function boundTo(string $typeSlug, array $fields): array
    {
        $out = [];
        foreach ($this->live($typeSlug) as $row) {
            $shown = self::fieldsShown($row['blocks']);
            $label = $this->surfaces->get((string) $row['surface'])?->label((string) $row['target']) ?? $row['target'];
            foreach ($fields as $field) {
                if (in_array($field, $shown, true)) {
                    $out[$field][] = $label;
                }
            }
        }
        return $out;
    }

    public function renameField(string $typeSlug, string $from, string $to): void
    {
        foreach ($this->live($typeSlug) as $listed) {
            $surface = (string) $listed['surface'];
            $target = (string) $listed['target'];
            // Re-read under the layout's own lock: a backfill write may have moved it since the list
            // (a save cannot — it waits on the type lock the migration holds).
            $this->lock->within($surface, $target, function () use ($surface, $target, $from, $to): void {
                $row = $this->layouts->find($surface, $target);
                if ($row === null || $row['blocks'] === null) {
                    return;
                }
                $changed = false;
                $blocks = self::rename($row['blocks'], $from, $to, $changed);
                if (!$changed) {
                    return;
                }
                if (!$this->layouts->persistBlocks($surface, $target, $row['lock_version'], $blocks)) {
                    throw new \RuntimeException("layout {$surface}:{$target} moved during a field rename");
                }
                $this->afterCommit($surface, $target);
            });
        }
    }

    public function tombstoneType(string $typeSlug): void
    {
        // Under the type's lock, as a save takes it, and each layout re-read under its own: a save
        // committing between the list and the write cannot turn the delete into a conflict.
        $this->lock->withinType($typeSlug, function () use ($typeSlug): void {
            foreach ($this->live($typeSlug) as $listed) {
                $surface = (string) $listed['surface'];
                $target = (string) $listed['target'];
                $this->lock->within($surface, $target, function () use ($surface, $target): void {
                    $row = $this->layouts->find($surface, $target);
                    if ($row === null || $row['blocks'] === null) {
                        return;
                    }
                    $this->layouts->tombstone($surface, $target, $row['lock_version'], null);
                    $this->afterCommit($surface, $target);
                });
            }
        });
    }

    /** @return list<array<string,mixed>> the type's live layouts */
    private function live(string $typeSlug): array
    {
        return array_values(array_filter(
            $this->layouts->forType($typeSlug),
            static fn (array $row): bool => is_array($row['blocks'] ?? null),
        ));
    }

    private function afterCommit(string $surface, string $target): void
    {
        $this->db->afterCommit(fn () => $this->changes->announce($surface, $target));
    }

    /**
     * The fields a tree's field blocks show: a chosen field, or an Entry content block's `body`.
     *
     * @param list<array<string,mixed>> $blocks
     * @return list<string>
     */
    private static function fieldsShown(array $blocks): array
    {
        $out = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];
            $type = (string) ($block['type'] ?? '');
            if (str_starts_with($type, 'entry_')) {
                $field = is_string($data['field'] ?? null) && $data['field'] !== ''
                    ? $data['field']
                    : ($type === 'entry_content' ? 'body' : null);
                if ($field !== null) {
                    $out[] = $field;
                }
            }
            foreach ($data as $value) {
                if (is_array($value) && array_is_list($value) && isset($value[0]['type'])) {
                    $out = [...$out, ...self::fieldsShown($value)];
                }
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * @param list<array<string,mixed>> $blocks
     * @return list<array<string,mixed>>
     */
    private static function rename(array $blocks, string $from, string $to, bool &$changed): array
    {
        foreach ($blocks as $i => $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = (string) ($block['type'] ?? '');
            $field = $block['data']['field'] ?? null;
            if (str_starts_with($type, 'entry_')) {
                $implicit = $type === 'entry_content' && ($field === null || $field === '') && $from === 'body';
                if ($field === $from || $implicit) {
                    $blocks[$i]['data']['field'] = $to;
                    $changed = true;
                }
            }
            foreach (is_array($block['data'] ?? null) ? $block['data'] : [] as $key => $value) {
                if (is_array($value) && array_is_list($value) && isset($value[0]['type'])) {
                    $blocks[$i]['data'][$key] = self::rename($value, $from, $to, $changed);
                }
            }
        }
        return $blocks;
    }
}
