<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Blocks\Sources;

use Thallo\Core\Content\Patterns\SavedSectionRepository;
use Thallo\Core\Content\Schema\ContentTypeSchema;

/**
 * The site's saved sections (the Blocks tab's library): one block each, presented as a document
 * with a single `blocks` field so every walker over stored blocks — a block type's migration, a
 * style class's usage and its detach- and remove-everywhere jobs — treats a saved section like a
 * region. Writes are conditional on the section's `lock_version`.
 */
final class SavedSectionsSource implements BlockDocumentSource
{
    public const ID = 'saved_section';

    private ?ContentTypeSchema $schema = null;

    public function __construct(private readonly SavedSectionRepository $sections)
    {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function each(callable $fn): void
    {
        foreach ($this->sections->all() as $row) {
            $fn(new DocumentRef(
                self::ID,
                $row['id'],
                null,
                (string) $row['lock_version'],
                $this->schema(),
                ['blocks' => [$row['block']]],
                ['name' => $row['name']],
            ));
        }
    }

    public function persist(DocumentRef $ref, array $fields, ?string $actor = null): bool
    {
        $block = is_array($fields['blocks'][0] ?? null) ? $fields['blocks'][0] : null;
        if ($block === null) {
            return false; // a saved section is one block: never written empty
        }
        return $this->sections->replaceBlock($ref->sourceId, (int) $ref->revision, $block);
    }

    /** A saved section's document schema: one blocks field named `blocks`, as a region's. */
    public function schema(): ContentTypeSchema
    {
        return $this->schema ??= ContentTypeSchema::fromArray([['name' => 'blocks', 'type' => 'blocks']]);
    }
}
