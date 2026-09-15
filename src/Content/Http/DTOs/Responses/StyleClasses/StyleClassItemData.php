<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs\Responses\StyleClasses;

use Glueful\Http\Contracts\ResponseData;

/**
 * Doc-only schema holder: one style class as the admin API returns it (visual builder spec
 * §4.1). NEVER constructed at runtime.
 */
final class StyleClassItemData implements ResponseData
{
    /** @param array<string,mixed> $style */
    public function __construct(
        public readonly string $id,
        public readonly int $version,
        public readonly string $name,
        public readonly ?string $description,
        /** The §1 schema: sparse breakpoints and resets included; no capabilities, no targets. */
        public readonly array $style,
        public readonly bool $archived,
        public readonly ?string $archived_at,
        /** The job holding the class, if any (spec §4.5): no edit and no new reference until it completes. */
        public readonly ?string $locked_by_job,
        public readonly ?string $created_at,
        public readonly ?string $updated_at,
    ) {
    }
}
