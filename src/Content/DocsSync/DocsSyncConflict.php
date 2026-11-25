<?php

declare(strict_types=1);

namespace Thallo\Core\Content\DocsSync;

/** A docs sync action refused because of the site's run slot or its source (§4, §6.1). */
final class DocsSyncConflict extends \RuntimeException
{
    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function running(): self
    {
        return new self('A docs sync is running. Wait for it to finish, or cancel it.', 'running');
    }

    public static function noSource(): self
    {
        return new self('No docs source is saved. Save one first.', 'no_source');
    }

    public static function sourceChanged(): self
    {
        return new self('The docs source just changed. Look at it again, then start the sync.', 'source_changed');
    }
}
