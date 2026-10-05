<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Fonts\Brotli;

use Thallo\Core\Content\Fonts\Brotli\Port\AllocationLimitExceeded;
use Thallo\Core\Content\Fonts\Brotli\Port\DeadlineExceeded;
use Thallo\Core\Content\Fonts\Brotli\Port\Decoder;
use Thallo\Core\Content\Fonts\Brotli\Port\OutputLimitExceeded;
use Thallo\Core\Content\Fonts\UnreadableFont;

/** Thallo's PHP port of google/brotli's decoder (Port/PROVENANCE.md), behind the bounded contract. */
final class PurePhpBrotliDecoder implements BrotliDecoder
{
    public function __construct(private readonly float $timeLimitSeconds = 10.0)
    {
    }

    public function decode(string $compressed, int $maxOutput): string
    {
        try {
            return Decoder::decompress($compressed, $maxOutput, $this->timeLimitSeconds);
        } catch (OutputLimitExceeded | AllocationLimitExceeded $e) {
            throw new UnreadableFont('This font is too large to read', $e);
        } catch (DeadlineExceeded $e) {
            throw new UnreadableFont('This font took too long to read', $e);
        } catch (\Throwable $e) {
            throw new UnreadableFont('Couldn\'t read this font\'s data', $e);
        }
    }
}
