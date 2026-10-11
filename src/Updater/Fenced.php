<?php

declare(strict_types=1);

namespace Thallo\Core\Updater;

/** A write by a runner that no longer owns the operation (spec §5): it is refused, never applied. */
final class Fenced extends \RuntimeException
{
}
