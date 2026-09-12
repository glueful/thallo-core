<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Events;

/**
 * Fired when a content entry's draft is updated.
 */
final class EntryUpdated extends BaseEntryEvent
{
    public function name(): string
    {
        return 'entry.updated';
    }
}
