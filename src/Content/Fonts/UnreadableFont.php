<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Fonts;

/** A font file Thallo can't read; $reason is the sentence shown to the person who uploaded it. */
final class UnreadableFont extends \RuntimeException
{
    public function __construct(public readonly string $reason, ?\Throwable $previous = null)
    {
        parent::__construct($reason, 0, $previous);
    }
}
