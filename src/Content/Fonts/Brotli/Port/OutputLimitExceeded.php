<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Fonts\Brotli\Port;

/** Decoding would produce more than the caller's output cap; stopped before appending past it. */
final class OutputLimitExceeded extends \RuntimeException
{
}
