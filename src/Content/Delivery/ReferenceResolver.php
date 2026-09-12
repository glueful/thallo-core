<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Delivery;

use Thallo\Core\Content\Blocks\BlockDepth;
use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Content\Schema\ContentTypeSchema;
use Glueful\Support\FieldSelection\FieldSelector;

/**
 * Resolves entry-UUID references in delivery rows to their target's **published**
 * version, at read time, with batch loading.
 *
 * V1_DESIGN §4: references point at *entries*; the delivery API resolves them to the
 * target's published version. An unpublished (or archived) target resolves to `null`
 * — never to a draft. Expansion uses batch loading: for each level of `$rootRows`,
 * all reference target uuids are collected and resolved with **one** query
 * (per locale set) via {@see DeliveryRepository::publishedByEntryUuids()}, not a
 * per-entry fetch. Circular references (A → A) are bounded by `$depth`; at the limit,
 * the reference value is left as the raw uuid (not expanded further). Asset fields
 * NEVER expand (spec §5): their values are blob uuids, raw at every level.
 *
 * Field selection: when a non-empty {@see FieldSelector} is provided, only reference
 * fields whose top-level path was requested are expanded. When `$selector` is `null`
 * or empty, ALL reference fields are expanded up to `$depth` — the controller
 * (Task 7) passes the real selector; this keeps the resolver usable standalone.
 */
final class ReferenceResolver
{
    public function __construct(
        private readonly DeliveryRepository $repo,
        /** null = no blocks descent (block refs stay raw); the container wires it. */
        private readonly ?BlockTypeRepository $blockTypes = null,
    ) {
    }

    /**
     * Expand the reference/asset fields of each root row in place.
     *
     * @param list<array<string,mixed>> $rootRows hydrated delivery rows (each has a
     *        decoded `fields` array)
     * @param ContentTypeSchema $schema the schema for the rows in $rootRows (used to
     *        know which fields are reference/asset)
     * @param FieldSelector|null $selector scopes which reference fields to expand;
     *        null/empty => expand all reference fields
     * @param string $locale resolve targets in this locale
     * @param int $depth remaining expansion depth (default 2); 0 stops recursion
     * @param list<string>|null $grantedScopes the caller's API-key scopes (null = anonymous);
     *        targets whose type the caller cannot read resolve to null. Threaded through the
     *        recursion so nested references are gated too.
     * @param ExpandedTargets|null $expanded records every target actually spliced (any depth)
     *        for Cache-Tag/ETag correctness (spec §4); null = no collection
     * @return list<array<string,mixed>> the rows with references spliced in
     */
    public function expand(
        array $rootRows,
        ContentTypeSchema $schema,
        ?FieldSelector $selector,
        string $locale,
        int $depth = 2,
        ?array $grantedScopes = null,
        ?ExpandedTargets $expanded = null,
    ): array {
        if ($rootRows === [] || $depth <= 0) {
            return $rootRows;
        }

        $referenceFields = $this->referenceFieldNames($schema, $selector);
        $blocksFields = $this->blocksFieldNames($schema, $selector);
        if ($referenceFields === [] && $blocksFields === []) {
            return $rootRows;
        }

        // 1) Collect every target uuid across all rows (one set, one query per level).
        $targetUuids = $this->collectTargets($rootRows, $referenceFields, $blocksFields);
        if ($targetUuids === []) {
            return $rootRows;
        }

        // 2) Batch-resolve the published versions in ONE query, gated by the caller's scopes.
        $resolved = $this->repo->publishedByEntryUuids($targetUuids, $locale, $grantedScopes);

        // 3) Recurse: expand references inside the resolved targets (same type/schema in
        //    the self-referential case; for cross-type we still use the source schema's
        //    reference field names, which is correct for the homogeneous v1 model). The
        //    targets share the schema only when they are the same type; to keep it safe
        //    and bounded we recurse with the same schema. Depth bounds the recursion.
        if ($depth - 1 > 0 && $resolved !== []) {
            $resolved = $this->indexExpanded(
                $this->expand(
                    array_values($resolved),
                    $schema,
                    $selector,
                    $locale,
                    $depth - 1,
                    $grantedScopes,
                    $expanded,
                ),
                array_keys($resolved)
            );
        }

        // 4) Splice resolved published `fields` back in place of each uuid.
        foreach ($rootRows as $i => $row) {
            /** @var array<string,mixed> $fields */
            $fields = $row['fields'] ?? [];
            foreach ($referenceFields as $field) {
                if (!array_key_exists($field, $fields)) {
                    continue;
                }
                $fields[$field] = $this->splice($fields[$field], $resolved, $expanded);
            }
            foreach ($blocksFields as $field) {
                if (!array_key_exists($field, $fields)) {
                    continue;
                }
                $fields[$field] = $this->spliceBlocks($fields[$field], $resolved, 1, $expanded);
            }
            $rootRows[$i]['fields'] = $fields;
        }

        return $rootRows;
    }

    /**
     * Re-key the recursively-expanded rows back to their entry uuids so {@see splice()}
     * can look them up. The expand() call preserves order, so we zip the expanded list
     * back onto the original key order.
     *
     * @param list<array<string,mixed>> $expandedRows
     * @param list<string> $keys
     * @return array<string,array<string,mixed>>
     */
    private function indexExpanded(array $expandedRows, array $keys): array
    {
        $out = [];
        foreach ($expandedRows as $i => $row) {
            $out[$keys[$i]] = $row;
        }
        return $out;
    }

    /**
     * Replace a reference field value (a uuid string, or a list of uuid strings) with
     * the resolved row(s). A scalar uuid → the resolved row or `null`. A list → each
     * element resolved independently (unpublished elements become `null`). Every row
     * actually spliced is recorded on the collector (spec §4); unresolved values
     * record nothing (surrogate-header privacy).
     *
     * @param array<string,array<string,mixed>> $resolved
     */
    private function splice(mixed $value, array $resolved, ?ExpandedTargets $expanded = null): mixed
    {
        if (is_string($value)) {
            return $this->resolveOne($value, $resolved, $expanded);
        }
        if (is_array($value)) {
            return array_map(
                fn(mixed $v): mixed => is_string($v) ? $this->resolveOne($v, $resolved, $expanded) : $v,
                array_values($value)
            );
        }
        return $value;
    }

    /** @param array<string,array<string,mixed>> $resolved */
    private function resolveOne(string $uuid, array $resolved, ?ExpandedTargets $expanded): mixed
    {
        $row = $resolved[$uuid] ?? null;
        if ($row !== null) {
            $expanded?->add((string) ($row['entry_uuid'] ?? ''), (string) ($row['version_uuid'] ?? ''));
        }
        return $row;
    }

    /**
     * The reference field names to expand, honouring the selector. Asset fields are
     * DELIBERATELY absent (spec §5): asset values are blob uuids — resolving them
     * against the published-entry spine is a category error (pre-fix it nulled
     * them). Assets stay raw at every level; media() consumes them at render.
     *
     * @return list<string>
     */
    private function referenceFieldNames(ContentTypeSchema $schema, ?FieldSelector $selector): array
    {
        $scoped = $selector !== null && !$selector->empty();
        $names = [];
        foreach ($schema->fields() as $field) {
            if ($field->type !== 'reference') {
                continue;
            }
            if ($scoped && !$selector->requested($field->name)) {
                continue;
            }
            $names[] = $field->name;
        }
        return $names;
    }

    /**
     * Entry-schema `blocks` fields — descent roots for block-ref expansion (spec §2),
     * under the SAME top-level selector rule as reference fields (spec §3: no
     * inner-block selectors). Empty when no registry is wired.
     *
     * @return list<string>
     */
    private function blocksFieldNames(ContentTypeSchema $schema, ?FieldSelector $selector): array
    {
        if ($this->blockTypes === null) {
            return [];
        }
        $scoped = $selector !== null && !$selector->empty();
        $names = [];
        foreach ($schema->fields() as $field) {
            if ($field->type !== 'blocks') {
                continue;
            }
            if ($scoped && !$selector->requested($field->name)) {
                continue;
            }
            $names[] = $field->name;
        }
        return $names;
    }

    /**
     * Collect the distinct target uuids referenced across all rows — top-level
     * reference fields plus reference fields inside blocks (any structural depth).
     *
     * @param list<array<string,mixed>> $rows
     * @param list<string> $fields
     * @param list<string> $blocksFields
     * @return list<string>
     */
    private function collectTargets(array $rows, array $fields, array $blocksFields): array
    {
        $uuids = [];
        foreach ($rows as $row) {
            /** @var array<string,mixed> $rowFields */
            $rowFields = $row['fields'] ?? [];
            foreach ($fields as $field) {
                foreach ($this->uuidsIn($rowFields[$field] ?? null) as $uuid) {
                    $uuids[$uuid] = true;
                }
            }
            foreach ($blocksFields as $field) {
                $this->collectFromBlocks($rowFields[$field] ?? null, 1, $uuids);
            }
        }
        return array_keys($uuids);
    }

    /**
     * Walk a blocks value collecting reference-target uuids. Structural recursion is
     * bounded by BlockDepth::MAX (data written around the API must not unbound the
     * walk); malformed items and unknown slugs are skipped — delivery never explodes
     * over data (spec §2). Asset fields are never collected (spec §1).
     *
     * @param array<string,bool> $uuids
     */
    private function collectFromBlocks(mixed $value, int $structDepth, array &$uuids): void
    {
        if (!is_array($value) || !array_is_list($value) || $structDepth > BlockDepth::MAX) {
            return;
        }
        foreach ($value as $item) {
            [$blockSchema, $data] = $this->blockItem($item);
            if ($blockSchema === null) {
                continue;
            }
            foreach ($blockSchema->fields() as $field) {
                if ($field->type === 'reference') {
                    foreach ($this->uuidsIn($data[$field->name] ?? null) as $uuid) {
                        $uuids[$uuid] = true;
                    }
                } elseif ($field->type === 'blocks') {
                    $this->collectFromBlocks($data[$field->name] ?? null, $structDepth + 1, $uuids);
                }
            }
        }
    }

    /**
     * Mirror of collectFromBlocks: splice resolved targets back into block data,
     * same walk, same guards, same structural cap.
     *
     * @param array<string,array<string,mixed>> $resolved
     */
    private function spliceBlocks(
        mixed $value,
        array $resolved,
        int $structDepth,
        ?ExpandedTargets $expanded,
    ): mixed {
        if (!is_array($value) || !array_is_list($value) || $structDepth > BlockDepth::MAX) {
            return $value;
        }
        foreach ($value as $i => $item) {
            [$blockSchema, $data] = $this->blockItem($item);
            if ($blockSchema === null) {
                continue;
            }
            foreach ($blockSchema->fields() as $field) {
                if (!array_key_exists($field->name, $data)) {
                    continue;
                }
                if ($field->type === 'reference') {
                    $data[$field->name] = $this->splice($data[$field->name], $resolved, $expanded);
                } elseif ($field->type === 'blocks') {
                    $data[$field->name] = $this->spliceBlocks(
                        $data[$field->name],
                        $resolved,
                        $structDepth + 1,
                        $expanded,
                    );
                }
            }
            $value[$i]['data'] = $data;
        }
        return $value;
    }

    /**
     * A block item's registry schema + data, or [null, []] for anything malformed:
     * non-array item, non-string type, unknown slug (registry includes deactivated
     * types — stored content referencing one still expands), non-array data.
     *
     * @return array{0: ?ContentTypeSchema, 1: array<string,mixed>}
     */
    private function blockItem(mixed $item): array
    {
        if (!is_array($item) || !is_string($item['type'] ?? null)) {
            return [null, []];
        }
        $schema = ($this->blockTypes?->schemasBySlug() ?? [])[$item['type']] ?? null;
        if ($schema === null || !is_array($item['data'] ?? null)) {
            return [null, []];
        }
        return [$schema, $item['data']];
    }

    /**
     * A reference value is a uuid string or a list of uuid strings.
     *
     * @return list<string>
     */
    private function uuidsIn(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            return [$value];
        }
        if (is_array($value)) {
            return array_values(array_filter(
                array_map(static fn(mixed $v): string => is_string($v) ? $v : '', $value),
                static fn(string $v): bool => $v !== ''
            ));
        }
        return [];
    }
}
