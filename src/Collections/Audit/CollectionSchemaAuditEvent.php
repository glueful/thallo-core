<?php

declare(strict_types=1);

namespace Thallo\Core\Collections\Audit;

use Glueful\Events\Contracts\BaseEvent;
use Glueful\Extensions\Audit\Contracts\AuditableEvent;
use Glueful\Extensions\Audit\Contracts\AuditableEventDefaults;

/**
 * App-side audit event for a collection schema lifecycle change (create / structural update / drop).
 *
 * The `thallo-collections` pack depends only on framework + contracts (never `glueful/audit`), so its
 * `Collection*` schema events stay pure. This App-owned event implements {@see AuditableEvent}, so
 * the Audit extension records it automatically — resolving the actor's email label and request
 * context itself. We supply only the semantic fields.
 */
final class CollectionSchemaAuditEvent extends BaseEvent implements AuditableEvent
{
    use AuditableEventDefaults;

    /**
     * @param 'created'|'updated'|'deleted' $action
     * @param array<string, mixed>          $metadata Extra context (e.g. the structural change kind).
     */
    public function __construct(
        private readonly string $action,
        private readonly string $collectionName,
        private readonly ?string $actorUuid,
        private readonly array $metadata = [],
    ) {
        parent::__construct();
    }

    public function auditAction(): string
    {
        return $this->action;
    }

    public function auditCategory(): string
    {
        return 'collections';
    }

    /** @return array{type:string,uuid:string,label:string} */
    public function auditTarget(): array
    {
        return ['type' => 'collection', 'uuid' => $this->collectionName, 'label' => $this->collectionName];
    }

    /**
     * The actor uuid; the Audit extension resolves the human-readable label (email → username → uuid).
     *
     * @return array{uuid?:string}
     */
    public function auditActor(): array
    {
        return $this->actorUuid !== null && $this->actorUuid !== '' ? ['uuid' => $this->actorUuid] : [];
    }

    /** @return array<string, mixed> */
    public function auditMetadata(): array
    {
        return array_filter($this->metadata, static fn ($v): bool => $v !== null);
    }
}
