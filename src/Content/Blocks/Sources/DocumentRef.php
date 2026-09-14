<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Blocks\Sources;

use Thallo\Core\Content\Schema\ContentTypeSchema;

/**
 * One block-bearing document as a source enumerates it (visual builder plan A4.4): where it
 * lives, the revision it was read at (what a persist is conditioned on), the schema its block
 * fields follow and its fields. The identity is source-generic so diagnostics and decisions
 * can name a draft, a retained version or a region the same way.
 */
final readonly class DocumentRef
{
    /**
     * @param array<string,mixed> $fields
     * @param array<string,mixed> $meta source-specific context (a content type slug for cache tags)
     */
    public function __construct(
        public string $sourceType,
        public string $sourceId,
        public ?string $locale,
        public string $revision,
        public ContentTypeSchema $schema,
        public array $fields,
        public array $meta = [],
    ) {
    }

    /** The identity a diagnostic or a decision is keyed by. */
    public function identity(): string
    {
        return "{$this->sourceType}:{$this->sourceId}:{$this->revision}";
    }
}
