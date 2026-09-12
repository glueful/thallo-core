<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Pipeline\Listeners;

use Thallo\Core\Content\Events\AssetAttached;
use Thallo\Core\Content\Events\AssetDetached;
use Glueful\Bootstrap\ApplicationContext;

/**
 * Maintains the `media_usage` reverse index — "which entries reference this blob" — from the
 * content asset-delta events, so the media library can answer "Used in" without scanning entry
 * field data.
 *
 *  - AssetAttached → record (blob_uuid, entry_uuid)
 *  - AssetDetached → remove that pair
 *
 * Best-effort: a projection failure must never break the content write that emitted the event
 * (the events fire after-commit, so the entry change is already durable regardless).
 *
 * Invoked as a lazy '@'-service callable, so the entry point is __invoke().
 */
final class MediaUsageProjector
{
    public function __construct(private readonly ApplicationContext $context)
    {
    }

    public function __invoke(object $event): void
    {
        try {
            if ($event instanceof AssetAttached) {
                $this->attach($event->asset, $event->entry);
            } elseif ($event instanceof AssetDetached) {
                $this->detach($event->asset, $event->entry);
            }
        } catch (\Throwable) {
            // Index maintenance is best-effort; never break the content operation.
        }
    }

    private function attach(string $blobUuid, string $entryUuid): void
    {
        $exists = db($this->context)->table('media_usage')
            ->where('blob_uuid', '=', $blobUuid)
            ->where('entry_uuid', '=', $entryUuid)
            ->first();
        if (is_array($exists)) {
            return;
        }

        db($this->context)->table('media_usage')->insert([
            'blob_uuid' => $blobUuid,
            'entry_uuid' => $entryUuid,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function detach(string $blobUuid, string $entryUuid): void
    {
        db($this->context)->table('media_usage')
            ->where('blob_uuid', '=', $blobUuid)
            ->where('entry_uuid', '=', $entryUuid)
            ->delete();
    }
}
