<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Palette\Sources;

use Glueful\Database\Connection;
use Thallo\Core\Content\Blocks\Sources\BlockContentTypes;
use Thallo\Core\Content\Blocks\Sources\BlockDocumentSource;
use Thallo\Core\Content\Blocks\Sources\DocumentRef;
use Thallo\Core\Content\Schema\ContentTypeSchema;
use Thallo\Core\Content\Style\Classes\StyleClassRepository;
use Thallo\Core\Content\Style\Classes\StyleClassVersionConflict;

/**
 * The site's style classes (custom palette spec §4.4), each a document of one `style` key, written
 * through the repository's versioned update — which dispatches StyleClassSaved — and refused while a
 * style class job holds the class (StyleClassLocked: the replacement retries on its next pass).
 */
final class StyleClassesSource implements BlockDocumentSource
{
    public const ID = 'style_class';

    public function __construct(
        private readonly Connection $db,
        private readonly StyleClassRepository $classes,
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function each(callable $fn): void
    {
        $rows = $this->db->table('style_classes')
            ->select(['id', 'name', 'style', 'version'])
            ->orderBy('id', 'ASC')
            ->get();
        foreach ($rows as $row) {
            $fn(new DocumentRef(
                self::ID,
                (string) $row['id'],
                null,
                (string) (int) $row['version'],
                ContentTypeSchema::fromArray([]),
                ['style' => BlockContentTypes::decode($row['style'] ?? null)],
                ['name' => (string) $row['name']],
            ));
        }
    }

    public function persist(DocumentRef $ref, array $fields, ?string $actor = null): bool
    {
        try {
            $this->classes->update($ref->sourceId, (int) $ref->revision, [
                'style' => is_array($fields['style'] ?? null) ? $fields['style'] : [],
            ]);
            return true;
        } catch (StyleClassVersionConflict) {
            return false;
        }
    }
}
