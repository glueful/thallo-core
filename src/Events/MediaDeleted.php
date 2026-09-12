<?php

declare(strict_types=1);

namespace Thallo\Core\Events;

use Glueful\Events\Contracts\BaseEvent;
use Glueful\Extensions\Audit\Contracts\AuditableEvent;
use Glueful\Extensions\Audit\Contracts\AuditableEventDefaults;

/**
 * Records a media (blob) soft-delete in the audit log.
 *
 * {@see \Thallo\Core\Http\Controllers\MediaAdminController::destroy()} soft-deletes via a raw `blobs`
 * status update that bypasses BlobRepository's entity events, so the deletion would otherwise go
 * unaudited (uploads are audited because they go through BlobRepository::create()). Dispatching
 * this AuditableEvent records the deletion in the `media` category, attributed to the acting user.
 */
final class MediaDeleted extends BaseEvent implements AuditableEvent
{
    use AuditableEventDefaults;

    public function __construct(
        private readonly string $blobUuid,
        private readonly ?string $name,
        private readonly ?string $actorUuid,
        private readonly ?string $actorLabel,
    ) {
        parent::__construct();
    }

    public function auditAction(): string
    {
        return 'deleted';
    }

    public function auditCategory(): string
    {
        return 'media';
    }

    /** @return array{type?:string|null,uuid?:string|null,label?:string|null} */
    public function auditTarget(): array
    {
        return ['type' => 'media', 'uuid' => $this->blobUuid, 'label' => $this->name];
    }

    /** @return array{uuid?:string|null,label?:string|null} */
    public function auditActor(): array
    {
        if ($this->actorUuid === null || $this->actorUuid === '') {
            return [];
        }
        $actor = ['uuid' => $this->actorUuid];
        if ($this->actorLabel !== null && $this->actorLabel !== '') {
            $actor['label'] = $this->actorLabel;
        }

        return $actor;
    }
}
