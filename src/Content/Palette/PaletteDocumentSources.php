<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Palette;

use Thallo\Core\Content\Blocks\Sources\BlockDocumentSource;
use Thallo\Core\Content\Blocks\Sources\DocumentRef;
use Thallo\Core\Content\Blocks\Sources\EntryDraftsSource;
use Thallo\Core\Content\Blocks\Sources\LayoutsSource;
use Thallo\Core\Content\Blocks\Sources\PublishedEntriesSource;
use Thallo\Core\Content\Blocks\Sources\RegionsSource;
use Thallo\Core\Content\Blocks\Sources\SavedSectionsSource;
use Thallo\Core\Content\Palette\Sources\LayoutSettingsSource;
use Thallo\Core\Content\Palette\Sources\RegionSettingsSource;
use Thallo\Core\Content\Palette\Sources\StyleClassesSource;

/**
 * The documents Replace rewrites (custom palette spec §4.4), in order: entry drafts, current
 * publications, regions, saved sections, layouts, the regions' and layouts' own style frames, and
 * style classes. Retained versions are not among them: history is never rewritten.
 */
final class PaletteDocumentSources
{
    /** @var list<BlockDocumentSource> */
    private readonly array $sources;

    public function __construct(BlockDocumentSource ...$sources)
    {
        $this->sources = array_values($sources);
    }

    /** @param callable(BlockDocumentSource, DocumentRef): void $fn */
    public function each(callable $fn): void
    {
        foreach ($this->sources as $source) {
            $source->each(static fn (DocumentRef $ref) => $fn($source, $ref));
        }
    }

    /** The same registry with the source of the double's id replaced by it (proofs only). */
    public function replacing(BlockDocumentSource $double): self
    {
        return new self(...array_map(
            static fn (BlockDocumentSource $s): BlockDocumentSource => $s->id() === $double->id() ? $double : $s,
            $this->sources,
        ));
    }

    /** How a source's documents are walked for colour tokens. */
    public static function kindOf(string $sourceType): string
    {
        return match ($sourceType) {
            EntryDraftsSource::ID, PublishedEntriesSource::ID => ColorTokenWalker::KIND_ENTRY,
            RegionsSource::ID, SavedSectionsSource::ID, LayoutsSource::ID => ColorTokenWalker::KIND_SECTION,
            RegionSettingsSource::ID => ColorTokenWalker::KIND_REGION,
            LayoutSettingsSource::ID => ColorTokenWalker::KIND_LAYOUT,
            StyleClassesSource::ID => ColorTokenWalker::KIND_CLASS,
            default => throw new \InvalidArgumentException("no palette walk for {$sourceType}"),
        };
    }
}
