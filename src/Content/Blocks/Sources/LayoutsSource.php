<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Blocks\Sources;

use Glueful\Database\Connection;
use Thallo\Contracts\Delivery\RenderedPageCachePurge;
use Thallo\Core\Content\Layouts\LayoutRepository;
use Thallo\Core\Content\Layouts\LayoutResolver;
use Thallo\Core\Content\Schema\ContentTypeSchema;

/**
 * The site's layouts (type layouts spec §5.7): one document per live layout, with a single `blocks`
 * field, so every walker over stored blocks — a block type's migration, a style class's usage and
 * its detach- and remove-everywhere jobs — reaches them as it reaches regions. A write is
 * conditional on the layout's `lock_version` and bumps it; once it commits, the resolver forgets
 * the layout and the pages it renders are purged.
 */
final class LayoutsSource implements BlockDocumentSource
{
    public const ID = 'layout';

    private ?ContentTypeSchema $schema = null;

    public function __construct(
        private readonly Connection $db,
        private readonly LayoutRepository $layouts,
        private readonly LayoutResolver $resolver,
        private readonly ?RenderedPageCachePurge $purge = null,
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
                $this->schema(),
                ['blocks' => $row['blocks']],
                ['surface' => $row['surface'], 'target' => $row['target']],
            ));
        }
    }

    public function persist(DocumentRef $ref, array $fields, ?string $actor = null): bool
    {
        $surface = (string) ($ref->meta['surface'] ?? '');
        $target = (string) ($ref->meta['target'] ?? '');
        $blocks = is_array($fields['blocks'] ?? null) ? array_values($fields['blocks']) : [];
        if (!$this->layouts->persistBlocks($surface, $target, (int) $ref->revision, $blocks)) {
            return false;
        }
        // After the outermost commit (at once when there is none): nothing shows a write that
        // could still roll back.
        $this->db->afterCommit(function () use ($surface, $target): void {
            $this->resolver->forget($surface, $target);
            $this->purge?->purge(["thallo:layout:{$surface}:{$target}"]);
        });
        return true;
    }

    /** A layout's document schema: one blocks field named `blocks`, as a region's. */
    public function schema(): ContentTypeSchema
    {
        return $this->schema ??= ContentTypeSchema::fromArray([['name' => 'blocks', 'type' => 'blocks']]);
    }
}
