<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Fonts;

use Glueful\Database\Connection;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Whether a blob is a font file this workspace may build a family from: an active, undeleted
 * `font/woff2` blob in the workspace's media library. With tenancy on, ownership is the scoped
 * `media_assets` row (as MediaAdminController::activeBlobs() reads the library); off, every blob is
 * the site's own.
 */
final class FontBlobCheck
{
    public const NOT_IN_LIBRARY = 'That file isn\'t in the media library';

    public function __construct(private readonly Connection $db, private readonly SystemFlags $flags)
    {
    }

    /**
     * @return array<string, mixed> the blob row
     * @throws UnreadableFont when the blob is missing, inactive, deleted, not WOFF2 or another workspace's
     */
    public function usable(string $blobUuid): array
    {
        $query = $this->flags->tenancyEnabled()
            ? $this->db->table('media_assets as ma')->join('blobs as b', 'b.uuid', '=', 'ma.blob_uuid')
            : $this->db->table('blobs as b');
        $blob = $query->select(['b.*'])
            ->where('b.uuid', '=', $blobUuid)
            ->where('b.status', '=', 'active')
            ->whereNull('b.deleted_at')
            ->where('b.mime_type', '=', 'font/woff2')
            ->first();
        if (!is_array($blob)) {
            throw new UnreadableFont(self::NOT_IN_LIBRARY);
        }
        return $blob;
    }
}
