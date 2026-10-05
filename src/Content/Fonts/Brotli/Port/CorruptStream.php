<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Fonts\Brotli\Port;

/** The stream breaks the Brotli format; the message names upstream's error code. */
final class CorruptStream extends \RuntimeException
{
}
