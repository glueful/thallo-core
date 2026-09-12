<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Request body for `PATCH /v1/admin/content-types/{slug}`
 * ({@see \Thallo\Core\Content\Http\Controllers\ContentTypeController::update()}).
 *
 * NON-SCHEMA metadata only (schema edits have their own endpoint; the slug is
 * immutable). null = unchanged. The headline field is `public_delivery`,
 * which was previously creation-only — leaving no UI/API path to make an
 * existing type publicly deliverable.
 */
final class UpdateContentTypeData implements RequestData
{
    public function __construct(
        #[Rule('string')]
        public readonly ?string $name = null,
        #[Rule('string')]
        public readonly ?string $description = null,
        #[Rule('numeric')]
        public readonly ?int $cache_ttl = null,
        #[Rule('boolean')]
        public readonly ?bool $public_delivery = null,
        /** @var bool|null Whether entries serve at /{slug} instead of /{type}/{slug}. */
        #[Rule('boolean')]
        public readonly ?bool $mount_at_root = null,
    ) {
    }
}
