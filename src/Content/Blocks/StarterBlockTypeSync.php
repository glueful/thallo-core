<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Blocks;

use Thallo\Core\Content\Starter\Kinds\BlockTypeKind;

/**
 * Syncs evolved starter block-type definitions onto existing rows — the counterpart of the
 * create-only {@see StarterBlockTypeSeeder}, run by `thallo:blocks:sync` and by every
 * `thallo:provision` once installed, so an upgrade needs no further command.
 *
 * Fields are additive: the row's order is preserved, a starter field absent from the row is
 * appended, a same-name field the starter now labels receives `enum_labels` — or now formats
 * receives its `format`, the hint that picks its editor — when the row has none, and nothing is
 * removed (the migration flow retires fields). The style declaration —
 * `style_capabilities`, `style_targets`, `flags` (visual builder spec §1.7) — is the starter's
 * to own: a row whose declaration differs takes the definition's, so a row seeded before the
 * declarations existed, or synced before a block's conversion, renders its settings after the
 * next sync. `starter_content` is filled only while the row has none.
 */
final class StarterBlockTypeSync
{
    public function __construct(
        private readonly BlockTypeRepository $blocks,
        private readonly BlockTypeKind $kind,
    ) {
    }

    /**
     * @return array{
     *   synced: list<array{slug: string, parts: list<string>}>,
     *   unchanged: int,
     *   missing: list<string>,
     * }
     */
    public function sync(bool $dryRun = false): array
    {
        $synced = [];
        $unchanged = 0;
        $missing = [];
        foreach ($this->kind->definitions() as $starter) {
            $definition = $starter->payload;
            $slug = (string) $definition['slug'];
            $row = $this->blocks->findBySlug($slug);
            if ($row === null) {
                $missing[] = $slug;
                continue;
            }
            [$schema, $added, $labelled] = self::mergedSchema($row['schema'], $definition['schema']);
            $styleKeys = self::staleStyleKeys($row, $definition);
            if ($added === [] && $labelled === [] && $styleKeys === []) {
                $unchanged++;
                continue;
            }
            if (!$dryRun && $styleKeys !== []) {
                $this->blocks->updateStyle(
                    (string) $row['uuid'],
                    $definition['style_capabilities'] ?? null,
                    $definition['style_targets'] ?? null,
                    $definition['flags'] ?? null,
                    $row['starter_content'] ?? $definition['starter_content'] ?? null,
                );
            }
            if (!$dryRun && ($added !== [] || $labelled !== [])) {
                $this->blocks->updateSchema(
                    (string) $row['uuid'],
                    $schema,
                    (string) $row['label'],
                    $row['icon'] !== null ? (string) $row['icon'] : null,
                    $row['description'] !== null ? (string) $row['description'] : null,
                    $row['category'] !== null ? (string) $row['category'] : null,
                );
            }
            $parts = [];
            if ($added !== []) {
                $parts[] = '+' . count($added) . ': ' . implode(', ', $added);
            }
            if ($labelled !== []) {
                $parts[] = 'labels: ' . implode(', ', $labelled);
            }
            if ($styleKeys !== []) {
                $parts[] = 'style: ' . implode(', ', $styleKeys);
            }
            $synced[] = ['slug' => $slug, 'parts' => $parts];
        }

        return ['synced' => $synced, 'unchanged' => $unchanged, 'missing' => $missing];
    }

    /**
     * The row's schema with the starter's missing fields appended and missing labels attached.
     *
     * @param list<array<string,mixed>> $row
     * @param list<array<string,mixed>> $starter
     * @return array{0: list<array<string,mixed>>, 1: list<string>, 2: list<string>}
     */
    private static function mergedSchema(array $row, array $starter): array
    {
        $existing = array_column($row, 'name');
        $added = [];
        $labelled = [];
        foreach ($starter as $field) {
            if (!in_array($field['name'], $existing, true)) {
                $row[] = $field;
                $added[] = (string) $field['name'];
                continue;
            }
            foreach (['enum_labels', 'format'] as $hint) {
                if (!isset($field[$hint])) {
                    continue;
                }
                foreach ($row as $i => $rowField) {
                    if (($rowField['name'] ?? null) === $field['name'] && !isset($rowField[$hint])) {
                        $row[$i][$hint] = $field[$hint];
                        $labelled[] = (string) $field['name'];
                    }
                }
            }
        }
        return [array_values($row), $added, $labelled];
    }

    /**
     * The style declaration keys whose stored value differs from the starter's (`starter_content`
     * only when the row has none). Objects compare by content: JSONB stores keys in its own order.
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed> $definition
     * @return list<string>
     */
    private static function staleStyleKeys(array $row, array $definition): array
    {
        $stale = [];
        foreach (['style_capabilities', 'style_targets', 'flags'] as $key) {
            if (self::canonical($row[$key] ?? null) !== self::canonical($definition[$key] ?? null)) {
                $stale[] = $key;
            }
        }
        if (($row['starter_content'] ?? null) === null && isset($definition['starter_content'])) {
            $stale[] = 'starter_content';
        }
        return $stale;
    }

    /** The value with every object's keys sorted, lists as they are. */
    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $out = array_map(self::canonical(...), $value);
        if (!array_is_list($out)) {
            ksort($out);
        }
        return $out;
    }
}
