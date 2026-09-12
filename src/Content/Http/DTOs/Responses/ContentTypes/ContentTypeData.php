<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs\Responses\ContentTypes;

use Glueful\Http\Contracts\ResponseData;
use Glueful\Validation\Attributes\ArrayOf;

/**
 * Doc-only schema holder: mirrors the raw `content_types` row the runtime emits as
 * the `content_type` object inside the success envelope (see {@see ContentTypeResultData}).
 * NEVER constructed at runtime — it exists only so the OpenAPI generator can reflect a
 * typed schema. Field types are chosen to drive the generated schema, not to match the
 * PHP wire type: `created_at`/`updated_at` are `\DateTimeInterface` to emit
 * `format: date-time` (the wire value is an ISO-8601-ish string), and `schema` items
 * are typed via #[ArrayOf].
 */
final class ContentTypeData implements ResponseData
{
    /** @param list<FieldSchemaData> $schema */
    public function __construct(
        public readonly int $id,
        public readonly string $uuid,
        public readonly string $slug,
        public readonly string $name,
        public readonly ?string $description,
        public readonly ?int $cache_ttl,
        public readonly bool $public_delivery,
        public readonly bool $mount_at_root,
        public readonly string $status,
        #[ArrayOf(FieldSchemaData::class)]
        public readonly array $schema,
        public readonly int $schema_version,
        public readonly ?string $created_by,
        public readonly \DateTimeInterface $created_at,
        public readonly ?\DateTimeInterface $updated_at,
    ) {
    }
}
