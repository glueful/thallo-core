<?php

declare(strict_types=1);

namespace Thallo\Core\Analytics;

use Thallo\Core\Content\Events\BaseEntryEvent;
use Thallo\Core\Content\Events\EntryCreated;
use Thallo\Core\Content\Events\EntryDeleted;
use Thallo\Core\Content\Events\EntryPublished;
use Thallo\Core\Content\Events\EntryUnpublished;
use Thallo\Core\Content\Events\EntryUpdated;
use Glueful\Events\Contracts\BaseEvent;
use Thallo\Analytics\Facts\AnalyticsFact;
use Thallo\Analytics\Facts\AnalyticsRecorder;
use Thallo\Collections\Events\CollectionCreated;
use Thallo\Collections\Events\CollectionDropped;
use Thallo\Collections\Events\CollectionRowCreated;
use Thallo\Collections\Events\CollectionRowDeleted;
use Thallo\Collections\Events\CollectionRowUpdated;
use Thallo\Collections\Events\CollectionUpdated;

/**
 * Bridges pack/content lifecycle events into analytics facts — the App-side seam so the pack stays
 * dependency-pure (it cannot reference thallo-collections or App content events). Mirrors
 * CollectionAuditListener.
 */
final class AnalyticsBridgeListener
{
    public function __construct(private readonly AnalyticsRecorder $recorder)
    {
    }

    public function __invoke(object $event): void
    {
        $ts = $event instanceof BaseEvent ? $event->getTimestamp() : microtime(true);
        $fact = match (true) {
            $event instanceof CollectionCreated =>
                $this->collection(
                    'collections.collection.created',
                    $event->collectionName,
                    $event->actorType,
                    $event->actorId,
                    $ts,
                ),
            $event instanceof CollectionUpdated =>
                $this->collection(
                    'collections.collection.updated',
                    $event->collectionName,
                    $event->actorType,
                    $event->actorId,
                    $ts,
                ),
            $event instanceof CollectionDropped =>
                $this->collection(
                    'collections.collection.dropped',
                    $event->collectionName,
                    $event->actorType,
                    $event->actorId,
                    $ts,
                ),
            $event instanceof CollectionRowCreated =>
                $this->collection(
                    'collections.row.created',
                    $event->collectionName,
                    $event->actor->type,
                    $event->actor->id,
                    $ts,
                ),
            $event instanceof CollectionRowUpdated =>
                $this->collection(
                    'collections.row.updated',
                    $event->collectionName,
                    $event->actor->type,
                    $event->actor->id,
                    $ts,
                ),
            $event instanceof CollectionRowDeleted =>
                $this->collection(
                    'collections.row.deleted',
                    $event->collectionName,
                    $event->actor->type,
                    $event->actor->id,
                    $ts,
                ),
            $event instanceof EntryCreated    => $this->entry('content.entry.created', $event, $ts),
            $event instanceof EntryUpdated    => $this->entry('content.entry.updated', $event, $ts),
            $event instanceof EntryDeleted    => $this->entry('content.entry.deleted', $event, $ts),
            $event instanceof EntryPublished  => $this->entry('content.entry.published', $event, $ts),
            $event instanceof EntryUnpublished => $this->entry('content.entry.unpublished', $event, $ts),
            default => null,
        };

        if ($fact !== null) {
            $this->recorder->record($fact);
        }
    }

    private function collection(
        string $event,
        string $name,
        string $actorType,
        ?string $actorId,
        float $ts,
    ): AnalyticsFact {
        return new AnalyticsFact(
            event: $event,
            category: 'collections',
            subjectType: 'collection',
            subjectId: $name,
            actorType: $actorType,
            actorId: $actorId,
            occurredAt: $ts,
        );
    }

    private function entry(string $event, BaseEntryEvent $e, float $ts): AnalyticsFact
    {
        // BaseEntryEvent: public readonly string $type (content-type slug), public readonly ?string $actor.
        // A null $actor is fine — the recorder records no active user for a null/non-human actor.
        return new AnalyticsFact(
            event: $event,
            category: 'content',
            subjectType: 'content_type',
            subjectId: $e->type,
            actorType: 'user',
            actorId: $e->actor,
            occurredAt: $ts,
        );
    }
}
