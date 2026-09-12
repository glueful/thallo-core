<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Pipeline\Listeners;

use Thallo\Core\Content\Events\EntryDeleted;
use Thallo\Core\Content\Events\EntryPublished;
use Thallo\Core\Content\Events\EntryUnpublished;
use Thallo\Core\Content\Repositories\PublishedReferenceRepository;

/**
 * Maintains the published-reference projection (term-archives/facets spec §1) on the
 * publishing pipeline's after-commit events. Idempotent delete-then-insert per
 * (entry, locale), so `thallo:resync` re-drives it exactly like the other effects.
 *
 * Wired BEFORE InvalidateCacheTagsListener in the listener map: the cache purge must
 * see a CURRENT projection, or a request racing the purge could re-cache stale facet
 * counts until the next event.
 */
final class ProjectPublishedReferencesListener
{
    public function __construct(private readonly PublishedReferenceRepository $projection)
    {
    }

    public function __invoke(object $event): void
    {
        if ($event instanceof EntryPublished) {
            if ($event->locale !== null) {
                $this->projection->projectFromPublished($event->entry, $event->type, $event->locale);
            }
            return;
        }
        if ($event instanceof EntryUnpublished) {
            if ($event->locale !== null) {
                $this->projection->clearForEntryLocale($event->entry, $event->locale);
            }
            return;
        }
        if ($event instanceof EntryDeleted) {
            $this->projection->clearForEntry($event->entry);
            $this->projection->clearForTarget($event->entry);
        }
    }
}
