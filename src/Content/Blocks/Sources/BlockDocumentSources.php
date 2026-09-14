<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Blocks\Sources;

/** The one registry of block-bearing document sources (visual builder plan A4.4). */
final class BlockDocumentSources
{
    /** @var list<BlockDocumentSource> */
    private readonly array $sources;

    public function __construct(BlockDocumentSource ...$sources)
    {
        $this->sources = array_values($sources);
    }

    /** @return list<BlockDocumentSource> */
    public function all(): array
    {
        return $this->sources;
    }

    /** The registry narrowed to the named sources, in registry order. */
    public function only(string ...$ids): self
    {
        return new self(...array_values(array_filter(
            $this->sources,
            static fn (BlockDocumentSource $s): bool => in_array($s->id(), $ids, true),
        )));
    }

    public function find(string $id): ?BlockDocumentSource
    {
        foreach ($this->sources as $source) {
            if ($source->id() === $id) {
                return $source;
            }
        }
        return null;
    }

    /** @param callable(BlockDocumentSource, DocumentRef): void $fn */
    public function each(callable $fn): void
    {
        foreach ($this->sources as $source) {
            $source->each(static fn (DocumentRef $ref) => $fn($source, $ref));
        }
    }
}
