<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Events;

/**
 * Fired when an asset is detached from an entry.
 */
final class AssetDetached extends BaseAssetEvent
{
    public function name(): string
    {
        return 'asset.detached';
    }
}
