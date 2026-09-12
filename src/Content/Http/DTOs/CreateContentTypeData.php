<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs;

use Glueful\Validation\Attributes\ArrayOf;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Request body for `POST /v1/admin/content-types`
 * ({@see \Thallo\Core\Content\Http\Controllers\ContentTypeController::store()}).
 *
 * Hydrated by the router (v2): the `slug` shape and the structure of each `schema` field
 * definition are validated here; semantic schema validation (valid types, `filter_type`,
 * enum values) and the duplicate-slug check stay in the controller/repository.
 */
final class CreateContentTypeData implements RequestData
{
    /** @param list<FieldDefinitionData> $schema */
    public function __construct(
        /** @var string Unique lowercase content-type slug (1–160 chars). */
        #[Rule('required|string|regex:/\A[a-z0-9][a-z0-9_-]{0,159}\z/')]
        public readonly string $slug,
        /** @var string Human-readable content-type name. */
        #[Rule('required|string')]
        public readonly string $name,
        /** @var string|null Optional description of the content type. */
        #[Rule('string')]
        public readonly ?string $description = null,
        /** @var int|null Optional delivery Cache-Control max-age override in seconds. */
        #[Rule('numeric')]
        public readonly ?int $cache_ttl = null,
        /** @var bool Whether published delivery routes may be read without an API key. */
        #[Rule('boolean')]
        public readonly bool $public_delivery = false,
        /** @var bool Whether entries serve at /{slug} instead of /{type}/{slug}. */
        #[Rule('boolean')]
        public readonly bool $mount_at_root = false,
        #[ArrayOf(FieldDefinitionData::class)]
        #[Rule('array')]
        public readonly array $schema = [],
    ) {
    }
}
