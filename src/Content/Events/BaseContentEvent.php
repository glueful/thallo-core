<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Events;

use Glueful\Events\Contracts\BaseEvent;
use Glueful\Extensions\Audit\Contracts\AuditableEvent;
use Thallo\Contracts\Events\ContentLifecycleEvent;

/**
 * Base class for every Thallo content domain event.
 *
 * The string returned by {@see name()} is a FROZEN public API contract
 * (V1_DESIGN §5): downstream listeners, webhook subscribers and the
 * `thallo:resync` command key off these names, so they must never drift.
 *
 * Payloads are deliberately minimal — entry/asset identity, actor and
 * timestamp — and NEVER carry a `fields` key. Receivers re-fetch the full
 * record through the delivery API using their own credentials; the event bus
 * is not a content channel.
 *
 * Three concrete shapes extend this base:
 *  - {@see BaseEntryEvent}  entry lifecycle  (entry, type, locale, version)
 *  - {@see BaseModelEvent}  content-type change (type only)
 *  - {@see BaseAssetEvent}  asset attach/detach (asset, source entry)
 *
 * Every content event is also an {@see AuditableEvent}: dispatching one through
 * the event bus records a `content`-category audit row via glueful/audit, with
 * no extra wiring. The verb is derived from name() and the identity payload
 * becomes the audit context; each shape base supplies {@see auditTarget()}.
 */
abstract class BaseContentEvent extends BaseEvent implements AuditableEvent, ContentLifecycleEvent
{
    public function __construct()
    {
        // BaseEvent assigns the event id + timestamp and provides the
        // metadata/propagation helpers.
        parent::__construct();
    }

    /**
     * The frozen event name (e.g. `entry.published`). Public API contract.
     */
    abstract public function name(): string;

    /**
     * The minimal, field-free event payload.
     *
     * Implementations MUST NOT include a `fields` key.
     *
     * @return array<string, mixed>
     */
    abstract public function payload(): array;

    /**
     * Audit verb, taken from the segment after the dot in name():
     * `entry.published` → `published`, `model.created` → `created`.
     */
    public function auditAction(): string
    {
        $name = $this->name();
        $dot = strrpos($name, '.');

        return $dot === false ? $name : substr($name, $dot + 1);
    }

    public function auditCategory(): string
    {
        return 'content';
    }

    /** @return array<string, array<string, mixed>>|null */
    public function auditChanges(): ?array
    {
        return null;
    }

    /**
     * The identity payload becomes the audit context, minus actor/timestamp
     * (which become their own columns) and any null values.
     *
     * @return array<string, mixed>
     */
    public function auditMetadata(): array
    {
        $payload = $this->payload();
        unset($payload['actor'], $payload['timestamp']);

        return array_filter($payload, static fn ($value): bool => $value !== null);
    }

    /**
     * What the event acted on — entry, content type, or asset.
     *
     * @return array{type?:string|null,uuid?:string|null,label?:string|null}
     */
    abstract public function auditTarget(): array;

    /**
     * Optional display label (email/username) for the actor, resolved from the actor uuid at
     * emit time. Content events dispatch after-commit, so the audit layer has no HTTP request to
     * resolve a label from; {@see PublishEventEmitter} populates this so the audit row shows a
     * human-readable actor instead of a bare uuid.
     */
    private ?string $auditActorLabel = null;

    /** Set the resolved actor display label (email/username); empty values are ignored. */
    public function setAuditActorLabel(?string $label): void
    {
        $this->auditActorLabel = ($label !== null && $label !== '') ? $label : null;
    }

    /**
     * The actor carried by the event (the user who saved/published), as a fallback for when there's
     * no HTTP request to resolve one from — these events dispatch after-commit and can also originate
     * from CLI (`thallo:resync`). The uuid comes from the payload; the label, when known, is supplied
     * by {@see PublishEventEmitter} via {@see setAuditActorLabel()}. Request resolution still wins
     * when an HTTP request is present.
     *
     * @return array{uuid?:string|null,label?:string|null}
     */
    public function auditActor(): array
    {
        $actor = $this->payload()['actor'] ?? null;
        if (!is_string($actor) || $actor === '') {
            return [];
        }
        $out = ['uuid' => $actor];
        if ($this->auditActorLabel !== null) {
            $out['label'] = $this->auditActorLabel;
        }

        return $out;
    }
}
