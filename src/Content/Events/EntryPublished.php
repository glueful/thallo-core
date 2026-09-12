<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Events;

/**
 * Fired when a content entry is published.
 */
final class EntryPublished extends BaseEntryEvent
{
    public function name(): string
    {
        return 'entry.published';
    }
}
