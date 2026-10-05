<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Fonts;

/** Hands the font library a readable copy of a media-library font file. */
interface FontBlobFiles
{
    /**
     * A temporary local copy of the blob's file; the caller deletes it once read.
     *
     * @throws UnreadableFont "That file isn't in the media library" for a missing, deleted, foreign or
     *                        non-WOFF2 blob
     */
    public function localPath(string $blobUuid): string;
}
