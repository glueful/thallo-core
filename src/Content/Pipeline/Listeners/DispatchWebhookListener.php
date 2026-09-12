<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Pipeline\Listeners;

use Thallo\Core\Content\Events\BaseContentEvent;
use Thallo\Core\Settings\GeneralSettings;
use Glueful\Api\Webhooks\Contracts\WebhookDispatcherInterface;
use Glueful\Api\Webhooks\WebhookDispatcher;
use Glueful\Bootstrap\ApplicationContext;
use Psr\Container\ContainerInterface;

/**
 * Forwards Thallo content events to the core webhook dispatcher (V1_DESIGN §5).
 *
 * Thallo builds no webhook infrastructure: it hands the event's frozen name() and its
 * identity-only payload() to the core WebhookDispatcher, which owns signing, retries and
 * delivery tracking. The dispatcher only creates deliveries for subscriptions that listen
 * to that event name, so registering this listener broadly (every content event) is safe —
 * no subscription means no delivery.
 *
 * Security model: the payload carries identity ONLY (entry/asset uuid, type, locale,
 * version, actor, timestamp) and NEVER the entry's field values. Receivers re-fetch the
 * full record through the delivery API with their own scoped key; the event bus is not a
 * content channel.
 *
 * The WebhookDispatcher is resolved from the container per-invocation rather than captured
 * in the constructor: this listener is a long-lived singleton registered at boot, so
 * resolving lazily means it always uses the current binding (and lets a test substitute
 * the dispatcher after boot). Mirrors InvalidateCacheTagsListener's structure.
 *
 * Registered via EventService::addListener(..., '@' . self::class) — the '@serviceId' form
 * resolves this service lazily and invokes it as a callable, so the entry point is
 * __invoke(object $event). Idempotent at Thallo's layer: it only ever dispatches once per
 * invocation; the core dispatcher / receiver handle delivery-level dedup.
 */
final class DispatchWebhookListener
{
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ApplicationContext $context,
    ) {
    }

    public function __invoke(object $event): void
    {
        if (!$event instanceof BaseContentEvent) {
            return;
        }

        if (!$this->webhooksEnabled()) {
            return;
        }

        $this->dispatcher()->dispatch($event->name(), $event->payload());
    }

    private function webhooksEnabled(): bool
    {
        return app($this->context, GeneralSettings::class)->webhooksEnabled();
    }

    private function dispatcher(): WebhookDispatcherInterface
    {
        // Resolve by the concrete service id (what CoreProvider binds the factory under,
        // and the id a test substitutes), but type against the interface so any compliant
        // dispatcher — including a test double — satisfies the contract.
        /** @var WebhookDispatcherInterface $dispatcher */
        $dispatcher = $this->container->get(WebhookDispatcher::class);
        return $dispatcher;
    }
}
