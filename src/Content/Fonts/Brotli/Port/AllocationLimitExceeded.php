<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Fonts\Brotli\Port;

/** The stream's headers ask for more working memory than the decoder's allocation budget. */
final class AllocationLimitExceeded extends \RuntimeException
{
}
