<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Fonts\Brotli;

use Thallo\Core\Content\Fonts\UnreadableFont;

/** Decodes a complete Brotli stream, bounded in output, memory and time (block typeface spec §2.3). */
interface BrotliDecoder
{
    /**
     * @throws UnreadableFont 'This font is too large to read' once the output would pass $maxOutput
     *                        (nothing past the cap is materialised); 'This font took too long to read'
     *                        past the decoder's deadline; 'Couldn't read this font's data' for a
     *                        corrupt, truncated or over-long stream
     */
    public function decode(string $compressed, int $maxOutput): string;
}
