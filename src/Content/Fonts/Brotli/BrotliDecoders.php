<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Fonts\Brotli;

/** Chooses the Brotli decoder for reading WOFF2 files. */
final class BrotliDecoders
{
    public static function best(): BrotliDecoder
    {
        // Always the PHP port: the brotli extension's API cannot bound output during expansion
        // (block typeface plan, Task 1 ruling).
        return new PurePhpBrotliDecoder();
    }
}
