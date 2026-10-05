<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Fonts;

/**
 * A font file Thallo can't read; $reason is the sentence shown to the person who uploaded it, and
 * $blobUuid the media library file it is about, when the reader knows.
 */
final class UnreadableFont extends \RuntimeException
{
    public function __construct(
        public readonly string $reason,
        ?\Throwable $previous = null,
        public readonly ?string $blobUuid = null,
    ) {
        parent::__construct($reason, 0, $previous);
    }

    /** The same refusal, about one media library file. */
    public function forBlob(string $blobUuid): self
    {
        return new self($this->reason, $this, $blobUuid);
    }
}
