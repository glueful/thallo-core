<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Style\Classes;

/**
 * The generation reads around a snapshot load never agreed (visual builder spec §4.3): the
 * request fails rather than serve a snapshot whose generation does not name its rows.
 */
final class StyleClassSnapshotUnstable extends \RuntimeException
{
}
