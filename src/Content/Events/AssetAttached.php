<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Events;

/**
 * Fired when an asset is attached to an entry.
 */
final class AssetAttached extends BaseAssetEvent
{
    public function name(): string
    {
        return 'asset.attached';
    }
}
