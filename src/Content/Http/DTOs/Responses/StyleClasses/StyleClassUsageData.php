<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs\Responses\StyleClasses;

use Glueful\Http\Contracts\ResponseData;

/**
 * Doc-only schema holder: `GET /v1/admin/style-classes/{id}/usage` (visual builder spec §4.3).
 * A reference is one occurrence of the class id in one stored document; `references` is the
 * sum of the four source counts and the published revision is counted once, under
 * `entry_published`. Per reference, each declared property is active where the block has the
 * capability and dormant otherwise. NEVER constructed at runtime.
 */
final class StyleClassUsageData implements ResponseData
{
    /**
     * @param array{entry_drafts: int, entry_published: int, entry_versions: int, regions: int} $by_source
     * @param array<string, array{active: int, dormant: int}> $properties
     */
    public function __construct(
        public readonly int $references,
        public readonly array $by_source,
        public readonly int $active,
        public readonly int $dormant,
        public readonly array $properties,
    ) {
    }
}
