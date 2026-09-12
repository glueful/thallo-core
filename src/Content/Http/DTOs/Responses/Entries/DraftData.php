<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs\Responses\Entries;

use Glueful\Http\Contracts\ResponseData;

/**
 * Doc-only schema holder: mirrors the raw `entry_drafts` row that
 * {@see \Thallo\Core\Content\Repositories\EntryRepository::findDraft()} returns after JSON-decoding
 * the `fields` column. NEVER constructed at runtime — it exists only so the OpenAPI
 * generator can reflect a typed schema for the `draft` key inside the success envelope.
 * `fields` is typed as `object` because it is a freeform per-content-type map whose
 * structure varies by schema; the reflector emits `{type: object}` for it.
 * `updated_at` is typed as `\DateTimeInterface` to drive `format: date-time` in the
 * generated schema; the wire value is an ISO-8601-ish string but the DTO is never
 * instantiated. There is no `created_at` column on `entry_drafts`.
 */
final class DraftData implements ResponseData
{
    public function __construct(
        public readonly int $id,
        public readonly string $entry_uuid,
        public readonly string $locale,
        public readonly object $fields,
        public readonly int $schema_version,
        public readonly int $lock_version,
        public readonly ?string $updated_by,
        public readonly \DateTimeInterface $updated_at,
    ) {
    }
}
