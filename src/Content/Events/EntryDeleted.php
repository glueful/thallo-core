<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Events;

/**
 * Fired when a content entry is deleted.
 */
final class EntryDeleted extends BaseEntryEvent
{
    public function name(): string
    {
        return 'entry.deleted';
    }
}
