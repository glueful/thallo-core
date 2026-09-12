<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Blocks\Migration;

use Thallo\Core\Content\Blocks\BlockDepth;
use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Content\Schema\ContentTypeSchema;
use Thallo\Core\Content\Schema\Migration\MigrationOpSet;

/**
 * The ONE structural walk over block instances inside entry fields — shared by the
 * backfill rewrite, the write gate, the restore projection, and the usage scan, so
 * "which blocks does this content contain" can never diverge between them.
 * Descent: entry-schema `blocks` fields, then nested `blocks` fields per the
 * registry schema (schemasBySlug includes deactivated types), capped at
 * BlockDepth::MAX. Malformed items and unknown slugs are skipped, never modified.
 */
final class BlockInstanceWalker
{
    public function __construct(private readonly BlockTypeRepository $registry)
    {
    }

    /**
     * DATA truth, not registry truth: every well-formed instance's type slug is
     * reported even when the registry no longer knows it (the restore projector
     * detects hard-deleted types this way). Descent into NESTED blocks needs the
     * registry schema, so unknown types' children are unreachable — acceptable:
     * their data cannot be interpreted anyway.
     *
     * @param array<string,mixed> $fields
     * @return list<string> distinct block-type slugs present (nested, capped)
     */
    public function slugsIn(array $fields, ContentTypeSchema $entrySchema): array
    {
        $found = [];
        foreach ($this->blocksFieldNames($entrySchema) as $name) {
            $this->collectSlugs($fields[$name] ?? null, 1, $found);
        }
        return array_keys($found);
    }

    /**
     * Apply $ops to the data of every instance of $slug (nested, capped).
     * MigrationCollisionException bubbles — the backfill records it as a failure;
     * gate/restore paths surface it.
     *
     * @param array<string,mixed> $fields
     * @return array{0: array<string,mixed>, 1: bool} [rewritten fields, changed]
     */
    public function rewrite(array $fields, ContentTypeSchema $entrySchema, string $slug, MigrationOpSet $ops): array
    {
        $changed = false;
        foreach ($this->blocksFieldNames($entrySchema) as $name) {
            if (!array_key_exists($name, $fields)) {
                continue;
            }
            $fields[$name] = $this->rewriteList($fields[$name], 1, $slug, $ops, $changed);
        }
        return [$fields, $changed];
    }

    /**
     * True when a matching instance still carries a rename-from/delete-name key —
     * the stampless equivalent of the content-type backfill's schema_version filter.
     *
     * @param array<string,mixed> $fields
     */
    public function hasOpSources(
        array $fields,
        ContentTypeSchema $entrySchema,
        string $slug,
        MigrationOpSet $ops,
    ): bool {
        $sources = $this->sourceKeys($ops);
        foreach ($this->blocksFieldNames($entrySchema) as $name) {
            if ($this->listHasSources($fields[$name] ?? null, 1, $slug, $sources)) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> */
    private function blocksFieldNames(ContentTypeSchema $entrySchema): array
    {
        $names = [];
        foreach ($entrySchema->fields() as $field) {
            if ($field->type === 'blocks') {
                $names[] = $field->name;
            }
        }
        return $names;
    }

    /** @return list<string> the op source keys (rename.from / delete.name) */
    private function sourceKeys(MigrationOpSet $ops): array
    {
        $keys = [];
        foreach ($ops->toArray() as $op) {
            $keys[] = (string) ($op['from'] ?? $op['name'] ?? '');
        }
        return array_values(array_filter($keys, static fn(string $k): bool => $k !== ''));
    }

    /** @param array<string,bool> $found */
    private function collectSlugs(mixed $list, int $depth, array &$found): void
    {
        if (!is_array($list) || !array_is_list($list) || $depth > BlockDepth::MAX) {
            return;
        }
        foreach ($list as $item) {
            // Raw shape check only — unknown-to-the-registry slugs ARE reported.
            if (!is_array($item) || !is_string($item['type'] ?? null) || !is_array($item['data'] ?? null)) {
                continue;
            }
            $found[$item['type']] = true;
            $schema = $this->registry->schemasBySlug()[$item['type']] ?? null;
            if ($schema === null) {
                continue; // no schema, no descent
            }
            foreach ($this->nestedBlockFields($schema) as $inner) {
                $this->collectSlugs($item['data'][$inner] ?? null, $depth + 1, $found);
            }
        }
    }

    private function rewriteList(mixed $list, int $depth, string $slug, MigrationOpSet $ops, bool &$changed): mixed
    {
        if (!is_array($list) || !array_is_list($list) || $depth > BlockDepth::MAX) {
            return $list;
        }
        foreach ($list as $i => $item) {
            [$type, $data, $schema] = $this->item($item);
            if ($type === null || $schema === null) {
                continue;
            }
            if ($type === $slug) {
                $applied = $ops->apply($data);
                if ($applied !== $data) {
                    $changed = true;
                    $data = $applied;
                }
            }
            foreach ($this->nestedBlockFields($schema) as $inner) {
                if (array_key_exists($inner, $data)) {
                    $data[$inner] = $this->rewriteList($data[$inner], $depth + 1, $slug, $ops, $changed);
                }
            }
            $list[$i]['data'] = $data;
        }
        return $list;
    }

    /** @param list<string> $sources */
    private function listHasSources(mixed $list, int $depth, string $slug, array $sources): bool
    {
        foreach ($this->items($list, $depth) as [$type, $data, $schema]) {
            if ($type === $slug) {
                foreach ($sources as $key) {
                    if (array_key_exists($key, $data)) {
                        return true;
                    }
                }
            }
            foreach ($this->nestedBlockFields($schema) as $inner) {
                if ($this->listHasSources($data[$inner] ?? null, $depth + 1, $slug, $sources)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Iterate well-formed items of a blocks list: yields [type, data, blockSchema].
     *
     * @return iterable<array{0: string, 1: array<string,mixed>, 2: ContentTypeSchema}>
     */
    private function items(mixed $list, int $depth): iterable
    {
        if (!is_array($list) || !array_is_list($list) || $depth > BlockDepth::MAX) {
            return;
        }
        foreach ($list as $item) {
            [$type, $data, $schema] = $this->item($item);
            if ($type !== null && $schema !== null) {
                yield [$type, $data, $schema];
            }
        }
    }

    /** @return array{0: ?string, 1: array<string,mixed>, 2: ?ContentTypeSchema} */
    private function item(mixed $item): array
    {
        if (!is_array($item) || !is_string($item['type'] ?? null) || !is_array($item['data'] ?? null)) {
            return [null, [], null];
        }
        $schema = $this->registry->schemasBySlug()[$item['type']] ?? null;
        if ($schema === null) {
            return [null, [], null];
        }
        return [$item['type'], $item['data'], $schema];
    }

    /** @return list<string> */
    private function nestedBlockFields(ContentTypeSchema $schema): array
    {
        $names = [];
        foreach ($schema->fields() as $field) {
            if ($field->type === 'blocks') {
                $names[] = $field->name;
            }
        }
        return $names;
    }
}
