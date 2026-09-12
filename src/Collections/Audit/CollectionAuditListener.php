<?php

declare(strict_types=1);

namespace Thallo\Core\Collections\Audit;

use Glueful\Events\EventService;
use Thallo\Collections\Events\CollectionCreated;
use Thallo\Collections\Events\CollectionDropped;
use Thallo\Collections\Events\CollectionRowCreated;
use Thallo\Collections\Events\CollectionRowDeleted;
use Thallo\Collections\Events\CollectionRowUpdated;
use Thallo\Collections\Events\CollectionUpdated;

/**
 * Bridges the pack's pure `CollectionRow*` (data) and `Collection*` (schema) events to the audit log.
 *
 * The `thallo-collections` pack depends only on framework + contracts (never `glueful/audit`), so its
 * events stay pure. This App-side listener maps each to a {@see CollectionRowAuditEvent} or
 * {@see CollectionSchemaAuditEvent} — an {@see \Glueful\Extensions\Audit\Contracts\AuditableEvent} the
 * Audit extension records automatically (resolving the actor's email label + request context), so
 * audit recording is not re-implemented here.
 */
final class CollectionAuditListener
{
    public function __construct(private readonly EventService $events)
    {
    }

    public function __invoke(object $event): void
    {
        $audit = match (true) {
            $event instanceof CollectionRowCreated =>
                new CollectionRowAuditEvent('created', $event->collectionName, $event->rowUuid, $event->actor->id),
            $event instanceof CollectionRowUpdated =>
                new CollectionRowAuditEvent('updated', $event->collectionName, $event->rowUuid, $event->actor->id),
            $event instanceof CollectionRowDeleted =>
                new CollectionRowAuditEvent('deleted', $event->collectionName, $event->rowUuid, $event->actor->id),
            $event instanceof CollectionCreated =>
                new CollectionSchemaAuditEvent('created', $event->collectionName, $event->actorId),
            $event instanceof CollectionUpdated =>
                new CollectionSchemaAuditEvent('updated', $event->collectionName, $event->actorId, [
                    'change' => $event->change,
                    'detail' => $event->detail,
                ]),
            $event instanceof CollectionDropped =>
                new CollectionSchemaAuditEvent('deleted', $event->collectionName, $event->actorId),
            default => null,
        };

        if ($audit !== null) {
            $this->events->dispatch($audit);
        }
    }
}
