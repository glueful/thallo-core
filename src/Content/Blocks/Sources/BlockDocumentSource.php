<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Blocks\Sources;

/**
 * A source of block-bearing documents (visual builder plan A4.4): entry drafts, every retained
 * entry version, regions. Every walker over stored blocks — the migration backfill, the
 * settings converter — iterates the registry of these, so a rename reaches a region and a
 * non-current version exactly as it reaches the current draft.
 */
interface BlockDocumentSource
{
    public function id(): string;

    /** @param callable(DocumentRef): void $fn called once per document */
    public function each(callable $fn): void;

    /**
     * Persist `$fields` for the document, atomically, only while it is still at the revision
     * the ref was read at.
     *
     * @param array<string,mixed> $fields
     * @param string|null $actor who is writing, for sources that record authorship
     * @return bool false when the document moved on (nothing written; re-enumerate and retry)
     */
    public function persist(DocumentRef $ref, array $fields, ?string $actor = null): bool;
}
