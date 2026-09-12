<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Delivery;

use Thallo\Core\Content\Indexing\FieldSqlExpression;
use Thallo\Core\Content\Schema\ContentTypeSchema;

/**
 * Compiles `?sort=field:dir` into a deterministic `ORDER BY` over the delivery query.
 *
 * Hard rules (V1_DESIGN §1/§6):
 *  - Only fields the schema marks `filterable` (with a `filter_type`) are sortable;
 *    everything else throws {@see UnfilterableFieldException}. Sorting an unindexed
 *    field would force a full JSONB scan — disallowed for latency predictability.
 *  - The sort expression is built from {@see FieldSqlExpression} — the SAME source the
 *    filterable-field expression index uses — so `ORDER BY` hits that index.
 *  - Only `asc`/`desc` directions; anything else throws {@see InvalidFilterException}.
 *  - A monotonic `v.id` tiebreaker is ALWAYS appended so paging is deterministic
 *    (two rows with equal sort values still have a stable, total order).
 *
 * The default order (no `?sort`) is `p.published_at DESC, v.id DESC` — newest first.
 *
 * `compile()` returns a descriptor the repository uses for BOTH the `ORDER BY` clause
 * and the keyset cursor predicate, guaranteeing the two agree on the sort expression:
 *   - `sql`:        the full `ORDER BY ...` string
 *   - `expr`:       the SQL expression of the primary sort key (for the keyset predicate)
 *   - `direction`:  'ASC' | 'DESC'
 *   - `field`:      the JSONB field name to read the cursor sort value from, or null
 *                   when sorting on the default `published_at` column
 *   - `column`:     the row column to read the cursor sort value from when `field` is
 *                   null (i.e. 'published_at')
 */
final class SortCompiler
{
    private const DIRECTIONS = ['asc' => 'ASC', 'desc' => 'DESC'];

    /**
     * @return array{sql:string,expr:string,direction:string,field:?string,column:?string}
     */
    public function compile(ContentTypeSchema $schema, ?string $sortParam): array
    {
        if ($sortParam === null || trim($sortParam) === '') {
            return self::defaultOrder();
        }

        [$fieldName, $dir] = $this->parse($sortParam);

        $field = $schema->field($fieldName);
        if ($field === null || !$field->filterable || $field->filterType === null) {
            throw new UnfilterableFieldException("field '{$fieldName}' is not sortable");
        }
        // Defense in depth: re-assert the name shape before interpolating into SQL.
        if (preg_match('/\A[a-z][a-z0-9_]*\z/', $field->name) !== 1) {
            throw new UnfilterableFieldException("unsafe field name: '{$field->name}'");
        }

        $expr = FieldSqlExpression::cast($field->name, (string) $field->filterType);

        // A row missing this (optional) field reads as NULL. Pin nulls LAST explicitly
        // for BOTH directions — Postgres defaults NULLS LAST for ASC but NULLS FIRST for
        // DESC, which would scatter missing-field rows differently per direction. With a
        // deterministic NULLS LAST the keyset predicate in DeliveryRepository can mirror
        // it (treat the null tail as coming after every non-null value) and never drop a
        // missing-field row from a page.
        return [
            'sql' => "ORDER BY {$expr} {$dir} NULLS LAST, v.id DESC",
            'expr' => $expr,
            'direction' => $dir,
            'field' => $field->name,
            'column' => null,
        ];
    }

    /**
     * The default newest-first order, independent of any schema. Exposed statically so
     * the repository can fall back to it without compiling a `?sort` param.
     *
     * @return array{sql:string,expr:string,direction:string,field:?string,column:?string}
     */
    public static function defaultOrder(): array
    {
        return [
            'sql' => 'ORDER BY p.published_at DESC, v.id DESC',
            'expr' => 'p.published_at',
            'direction' => 'DESC',
            'field' => null,
            'column' => 'published_at',
        ];
    }

    /** @return array{0:string,1:string} [fieldName, normalizedDirection] */
    private function parse(string $sortParam): array
    {
        $parts = explode(':', trim($sortParam), 2);
        $fieldName = trim($parts[0]);
        $dirRaw = isset($parts[1]) ? strtolower(trim($parts[1])) : 'asc';

        if ($fieldName === '') {
            throw new InvalidFilterException('sort field is required');
        }
        if (!isset(self::DIRECTIONS[$dirRaw])) {
            throw new InvalidFilterException("invalid sort direction '{$dirRaw}' (use asc|desc)");
        }

        return [$fieldName, self::DIRECTIONS[$dirRaw]];
    }
}
