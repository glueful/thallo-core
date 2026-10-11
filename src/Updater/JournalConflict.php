<?php

declare(strict_types=1);

namespace Thallo\Core\Updater;

/** The journal cannot tell from the filesystem whether an intended rename happened (both or neither path exist). */
final class JournalConflict extends \RuntimeException
{
}
