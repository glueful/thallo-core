<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Palette\Sources;

use Glueful\Database\Connection;
use Thallo\Core\Content\Blocks\Sources\BlockDocumentSource;
use Thallo\Core\Content\Blocks\Sources\DocumentRef;
use Thallo\Core\Content\Layouts\LayoutChanges;
use Thallo\Core\Content\Layouts\LayoutRepository;
use Thallo\Core\Content\Schema\ContentTypeSchema;

/**
 * A layout's frame (custom palette spec §4.4): each live layout's `settings`, as a document of one
 * `settings` key. A write forgets the layout and purges its pages after commit, as a save does.
 */
final class LayoutSettingsSource implements BlockDocumentSource
{
    public const ID = 'layout_settings';

    public function __construct(
        private readonly Connection $db,
        private readonly LayoutRepository $layouts,
        private readonly ?LayoutChanges $changes = null,
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function each(callable $fn): void
    {
        foreach ($this->layouts->live() as $row) {
            $fn(new DocumentRef(
                self::ID,
                $row['surface'] . ':' . $row['target'],
                null,
                (string) $row['lock_version'],
                ContentTypeSchema::fromArray([]),
                ['settings' => is_array($row['settings'] ?? null) ? $row['settings'] : []],
                ['surface' => $row['surface'], 'target' => $row['target']],
            ));
        }
    }

    public function persist(DocumentRef $ref, array $fields, ?string $actor = null): bool
    {
        $surface = (string) ($ref->meta['surface'] ?? '');
        $target = (string) ($ref->meta['target'] ?? '');
        $settings = is_array($fields['settings'] ?? null) ? $fields['settings'] : [];
        if (!$this->layouts->persistSettings($surface, $target, (int) $ref->revision, $settings)) {
            return false;
        }
        $this->db->afterCommit(fn () => $this->changes?->announce($surface, $target));
        return true;
    }
}
