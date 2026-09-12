<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Events;

/**
 * Base for asset attach/detach events.
 *
 * Payload shape: asset (blob uuid), entry (source entry uuid the asset is
 * attached to / detached from), actor, timestamp.
 */
abstract class BaseAssetEvent extends BaseContentEvent
{
    public function __construct(
        public readonly string $asset,
        public readonly string $entry,
        public readonly ?string $actor = null,
    ) {
        parent::__construct();
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'asset' => $this->asset,
            'entry' => $this->entry,
            'actor' => $this->actor,
            'timestamp' => $this->getTimestamp(),
        ];
    }

    /**
     * @return array{type:string,uuid:string,label:null}
     */
    public function auditTarget(): array
    {
        return ['type' => 'asset', 'uuid' => $this->asset, 'label' => null];
    }
}
