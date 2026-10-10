<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Palette;

use Thallo\Contracts\Delivery\RenderedPageCachePurge;
use Thallo\Core\Content\Blocks\Sources\DocumentRef;
use Thallo\Core\Content\Blocks\Sources\PublishedEntriesSource;
use Thallo\Core\Content\Blocks\Sources\RegionsSource;
use Thallo\Core\Content\Palette\Sources\RegionSettingsSource;
use Thallo\Core\Content\Palette\Sources\StyleClassesSource;

/**
 * What a page cache needs after Replace rewrote one document (custom palette spec §4.4), run after
 * that write's commit — so a job that later fails has already invalidated every page it rewrote.
 * A publication purges its entry's and type's pages; a region's blocks or frame, and a style class,
 * every page; a layout's write announces itself; a draft and a saved section render nowhere yet.
 */
final class PaletteCacheEffects
{
    public function __construct(private readonly ?RenderedPageCachePurge $purge = null)
    {
    }

    public function afterWrite(DocumentRef $ref): void
    {
        if ($this->purge === null) {
            return;
        }
        switch ($ref->sourceType) {
            case PublishedEntriesSource::ID:
                $tags = ['thallo:entry:' . (string) ($ref->meta['entry_uuid'] ?? $ref->sourceId)];
                if (isset($ref->meta['content_type'])) {
                    $tags[] = 'thallo:type:' . (string) $ref->meta['content_type'];
                }
                $this->purge->purge($tags);
                return;
            case RegionsSource::ID:
            case RegionSettingsSource::ID:
            case StyleClassesSource::ID:
                $this->purge->purge(['thallo:render:page']);
                return;
            default:
                return; // drafts, saved sections; layouts announce their own write
        }
    }
}
