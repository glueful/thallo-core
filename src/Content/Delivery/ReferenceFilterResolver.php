<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Delivery;

use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Glueful\Database\Connection;
use Thallo\Contracts\Delivery\ReferenceTargetResolver;
use Thallo\Contracts\Schema\FieldDescriptor;

/**
 * Resolves reference-filter input values (uuids and/or slugs) to a deduped list of target entry
 * uuids, scoped to the field's target content type's published spine in the delivery locale.
 *
 * Per-input precedence: a value that equals a published target entry_uuid resolves to that uuid;
 * otherwise it resolves by the configured slug field (0 rows → dropped; >1 → ambiguous, throws).
 */
final class ReferenceFilterResolver implements ReferenceTargetResolver
{
    public function __construct(
        private readonly Connection $db,
        private readonly ContentTypeRepository $types,
    ) {
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    public function resolve(FieldDescriptor $field, string $locale, array $values): array
    {
        if ($values === []) {
            return [];
        }

        $targetSlug = $field->referenceType() ?? '';
        $targetRow = $targetSlug !== '' ? $this->types->findBySlug($targetSlug) : null;
        if ($targetRow === null || !isset($targetRow['uuid'])) {
            return []; // unknown target type → matches nothing
        }

        $slugField = $field->referenceSlugField() ?? 'slug';
        // Slug field is a schema identifier — interpolate (never bind) so the lookup can hit the
        // slug field's expression index. Re-assert the safe shape here.
        if (preg_match('/\A[a-z][a-z0-9_]*\z/', $slugField) !== 1) {
            throw new InvalidFilterException("unsafe reference_slug_field: '{$slugField}'");
        }

        $slugExpr = "v.fields ->> '{$slugField}'";
        $placeholders = implode(', ', array_fill(0, count($values), '?'));

        /** @var list<array<string,mixed>> $rows */
        $rows = $this->db->table('entry_publications as p')
            ->join('entry_versions as v', 'v.uuid', '=', 'p.version_uuid')
            ->join('entries as e', 'e.uuid', '=', 'p.entry_uuid')
            ->selectRaw("p.entry_uuid as uuid, {$slugExpr} as slug")
            ->where('e.content_type_uuid', '=', (string) $targetRow['uuid'])
            ->where('e.status', '=', 'active')
            ->where('p.locale', '=', $locale)
            ->whereRaw(
                "(p.entry_uuid IN ({$placeholders}) OR {$slugExpr} IN ({$placeholders}))",
                [...$values, ...$values]
            )
            ->get();

        /** @var array<string,true> $isUuid */
        $isUuid = [];
        /** @var array<string,array<string,true>> $bySlug */
        $bySlug = [];
        foreach ($rows as $r) {
            $uuid = (string) $r['uuid'];
            $isUuid[$uuid] = true;
            $slug = $r['slug'] ?? null;
            if (is_string($slug) && $slug !== '') {
                $bySlug[$slug][$uuid] = true;
            }
        }

        /** @var array<string,true> $resolved */
        $resolved = [];
        foreach ($values as $val) {
            if (isset($isUuid[$val])) {
                $resolved[$val] = true; // uuid precedence
                continue;
            }
            $matches = array_keys($bySlug[$val] ?? []);
            if (count($matches) === 0) {
                continue; // no match → dropped
            }
            if (count($matches) > 1) {
                throw new InvalidFilterException("ambiguous slug '{$val}' for reference filter");
            }
            $resolved[$matches[0]] = true;
        }

        return array_keys($resolved);
    }
}
