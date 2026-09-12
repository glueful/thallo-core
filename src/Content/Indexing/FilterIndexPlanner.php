<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Indexing;

use Thallo\Core\Content\Schema\ContentTypeSchema;
use Thallo\Core\Content\Indexing\FieldSqlExpression;

/**
 * Derives the desired Postgres expression indexes for a content type's filterable fields.
 *
 * The index expression's cast is fixed by the field's declared `filter_type` (V1_DESIGN §1)
 * and MUST match the cast the FilterCompiler (Task 4) generates, so the planner is the single
 * source of truth for both the index and the query predicate's typing.
 */
final class FilterIndexPlanner
{
    /**
     * Build the set of expression indexes a content type's current schema wants.
     *
     * Scalar filterable fields (filter_type declared) emit a btree expression index.
     * Membership filterable fields (reference/asset with filterable=true, no scalar
     * filter_type) emit a GIN expression index using the normalized jsonb array
     * expression from {@see FieldSqlExpression::membershipArray()}.
     *
     * @return list<array{field:string,filter_type:string,method:string,index_name:string,expression:string}>
     */
    public function desiredIndexes(ContentTypeSchema $schema, string $typeUuid): array
    {
        $out = [];
        foreach ($schema->fields() as $field) {
            $membership = $field->filterable && in_array($field->type, ['reference', 'asset'], true);
            $scalar = $field->filterable && $field->filterType !== null;
            if (!$membership && !$scalar) {
                continue;
            }
            $name = $field->name;
            // Defense in depth: the field name is already validated [a-z][a-z0-9_]* by
            // FieldDefinition, but we re-assert it before interpolating into raw DDL.
            if (preg_match('/\A[a-z][a-z0-9_]*\z/', $name) !== 1) {
                throw new \InvalidArgumentException("unsafe field name for index expression: '{$name}'");
            }
            $out[] = $membership
                ? [
                    'field'      => $name,
                    'filter_type' => $field->type, // 'reference' | 'asset'
                    'method'     => 'gin',
                    'index_name' => 'fidx_' . substr(sha1($typeUuid . $name), 0, 16),
                    'expression' => '(' . FieldSqlExpression::membershipArray($name) . ') jsonb_path_ops',
                ]
                : [
                    'field'      => $name,
                    'filter_type' => (string) $field->filterType,
                    'method'     => 'btree',
                    'index_name' => 'fidx_' . substr(sha1($typeUuid . $name), 0, 16),
                    'expression' => $this->expression($name, (string) $field->filterType),
                ];
        }
        return $out;
    }

    /**
     * The JSONB expression (with the type cast) used for the index DDL.
     *
     * The inner cast comes from {@see FieldSqlExpression::cast()} — the SAME source the
     * FilterCompiler (Task 4) predicate uses — wrapped in the outer parens Postgres
     * requires for an expression index, so the index and the predicate cannot drift.
     *
     * datetime is indexed/compared as TEXT (the only IMMUTABLE option — a
     * ::timestamptz/::timestamp cast is DateStyle-dependent so CREATE INDEX rejects it).
     * Text comparison sorts datetimes chronologically only when values are stored as
     * canonical ISO-8601 UTC; FieldValidator::normalizeDatetime enforces that on write.
     */
    private function expression(string $field, string $filterType): string
    {
        return '(' . FieldSqlExpression::cast($field, $filterType) . ')';
    }
}
