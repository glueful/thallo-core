<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs\Responses\Preview;

use Glueful\Http\Contracts\ResponseData;

/**
 * Doc-only schema holder: mirrors the array returned by both branches of
 * {@see \Thallo\Core\Content\Preview\PreviewReader::read()} — the draft branch (version_uuid
 * and version are null) and the pinned-version branch (version_uuid and version are set).
 * NEVER constructed at runtime — it exists only so the OpenAPI generator can reflect a
 * typed schema for the `preview` key inside the success envelope of
 * {@see \Thallo\Core\Content\Http\Controllers\PreviewController::show()} (HTTP 200).
 * `fields` is typed as `object` because the map is freeform and content-type-specific;
 * the OpenAPI generator emits `{type: object}` and drift tests never recurse into it.
 */
final class PreviewData implements ResponseData
{
    public function __construct(
        public readonly string $entry_uuid,
        public readonly string $locale,
        public readonly ?string $version_uuid,
        public readonly ?int $version,
        public readonly int $schema_version,
        public readonly object $fields,
    ) {
    }
}
