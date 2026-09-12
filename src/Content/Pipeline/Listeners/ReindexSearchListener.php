<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Pipeline\Listeners;

use Thallo\Core\Content\Events\BaseEntryEvent;
use Thallo\Contracts\Search\ContentReindexer;
use Psr\Container\ContainerInterface;

/**
 * Requests a provider-neutral search reindex for an entry when content changes (V1_DESIGN §5).
 *
 * CAPABILITY-GATED. The default Thallo install enables users/aegis/media/email but NOT
 * a search provider, so by default there is NO content reindexer bound and this listener is
 * a CLEAN skip — no error, no exception.
 *
 * The gate is the presence of Thallo's provider-neutral reindex seam in the container:
 * {@see ContentReindexer}. Any search extension can bind this contract and decide
 * whether to perform the work synchronously, enqueue its own job, or fan out to an external
 * engine. Thallo never imports provider-specific classes.
 *
 * Why a reindexer contract, not a direct adapter call: reindexing is heavy and the search
 * extension owns the document shape. Thallo's side is deliberately minimal — when the
 * capability is present it passes an IDENTITY-ONLY request (entry uuid + locale, NEVER field
 * values). The provider re-reads the published version through the delivery API and builds
 * the document. This keeps Thallo free of any search infrastructure and vendor names.
 *
 * Registered for entry lifecycle events only (publish/unpublish/update/delete) — a published
 * document's searchable state changes on each. Model/asset events do not move a single
 * entry's index document, so they are not wired to this listener.
 *
 * The seam is resolved from the container per-invocation (not the constructor): this is a
 * long-lived singleton registered at boot, so lazy resolution always uses the current binding
 * and lets a test substitute spies after boot.
 *
 * Registered via EventService::addListener(..., '@' . self::class) — the '@serviceId' form
 * resolves this service lazily and invokes it as a callable, so the entry point is
 * __invoke(object $event). Idempotent + re-drivable: re-pushing a reindex job re-derives the
 * same document, so `thallo:resync` can safely re-run it.
 */
final class ReindexSearchListener
{
    public function __construct(
        private readonly ContainerInterface $container,
    ) {
    }

    public function __invoke(object $event): void
    {
        if (!$event instanceof BaseEntryEvent) {
            return;
        }

        // Gate: only act when a search provider has bound Thallo's reindex seam.
        if (!$this->container->has(ContentReindexer::class)) {
            return;
        }

        /** @var ContentReindexer $reindexer */
        $reindexer = $this->container->get(ContentReindexer::class);
        $reindexer->reindexEntry($event->entry, $event->locale);
    }
}
