<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Console;

use Thallo\Core\Content\Delivery\DeliveryRepository;
use Thallo\Core\Content\Delivery\SortCompiler;
use Thallo\Core\Content\Events\EntryPublished;
use Thallo\Core\Content\Pipeline\Listeners\DispatchWebhookListener;
use Thallo\Core\Content\Pipeline\Listeners\InvalidateCacheTagsListener;
use Thallo\Core\Content\Pipeline\Listeners\ProjectPublishedReferencesListener;
use Thallo\Core\Content\Pipeline\Listeners\PurgeCdnListener;
use Thallo\Core\Content\Pipeline\Listeners\ReindexSearchListener;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Re-drives the publishing pipeline's idempotent downstream effects for published content
 * (V1_DESIGN §5).
 *
 * WHY THIS EXISTS: the pipeline dispatches its effects from `db()->afterCommit(...)`, which
 * is in-process. A crash between the commit and the callback DROPS those effects — the entry
 * is published in the database but its cache was never invalidated and its search document was
 * never reindexed. `thallo:resync` walks the PUBLISHED set and re-fires the re-drivable
 * effects so the read side reconverges.
 *
 * SCOPE (mutually-narrowing, all published-only):
 *   --entry=UUID  one published entry (every locale it is published in)
 *   --type=SLUG   every published entry of one content type
 *   (no option)   every published entry across every content type
 *
 * It reads through {@see DeliveryRepository} — the leak-proof read path whose every query is
 * anchored to the publication spine — so resync can NEVER surface or act on a draft or an
 * archived entry. The per-type walk is keyset-PAGED ({@see DeliveryRepository::listPublished}
 * + a cursor), so the no-args "everything" case streams in bounded batches instead of loading
 * the world into memory.
 *
 * RE-DRIVEN EFFECTS (Option A — direct listener invocation, for precise control):
 *   - ProjectPublishedReferencesListener (always) rebuild published-reference projection rows
 *   - InvalidateCacheTagsListener  (always)  drop thallo:entry:{uuid} + thallo:type:{slug}
 *   - PurgeCdnListener             (always)  idempotent + capability-gated (no-ops without cdn)
 *   - ReindexSearchListener        (always)  idempotent + capability-gated (no-ops without search)
 *   - DispatchWebhookListener      (ONLY with --webhooks)
 *
 * Webhooks are OPT-IN: re-firing them surprises receivers with duplicate deliveries, so the
 * default resync rebuilds the read side WITHOUT touching webhooks. Pass --webhooks to also
 * re-dispatch the publish event to subscribers.
 *
 * Idempotent + safe to re-run: every re-driven effect is itself idempotent (invalidating an
 * already-clear tag, re-pushing a reindex job that re-derives the same document).
 */
#[AsCommand(
    name: 'thallo:resync',
    description: 'Re-drive the publishing pipeline (projection + cache + search, optionally webhooks) '
        . 'for published content',
)]
final class ResyncCommand extends BaseCommand
{
    /** Keyset page size for the per-type published walk (bounded memory). */
    private const BATCH = 200;

    protected function configure(): void
    {
        $this
            ->setDescription(
                'Re-drive the publishing pipeline (projection + cache + search, optionally webhooks) '
                . 'for published content'
            )
            ->setHelp(
                'Re-fires the idempotent downstream effects for published content after a crash dropped '
                . "the in-process afterCommit callbacks.\n\n"
                . "  thallo:resync --entry=UUID   one published entry\n"
                . "  thallo:resync --type=SLUG     every published entry of a type\n"
                . "  thallo:resync                 every published entry across all types\n\n"
                . 'Webhooks are NOT re-fired unless --webhooks is given.'
            )
            ->addOption('entry', null, InputOption::VALUE_REQUIRED, 'Resync a single published entry by uuid')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Resync every published entry of a type slug')
            ->addOption('webhooks', null, InputOption::VALUE_NONE, 'Also re-dispatch webhooks (opt-in; off default)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $entryOpt = $input->getOption('entry');
        $typeOpt = $input->getOption('type');
        $withWebhooks = (bool) $input->getOption('webhooks');

        $entryUuid = is_string($entryOpt) && $entryOpt !== '' ? $entryOpt : null;
        $typeSlug = is_string($typeOpt) && $typeOpt !== '' ? $typeOpt : null;

        if ($entryUuid !== null && $typeSlug !== null) {
            $this->error('Pass only one of --entry or --type.');
            return self::FAILURE;
        }

        $count = 0;
        if ($entryUuid !== null) {
            $count = $this->resyncEntry($entryUuid, $withWebhooks);
        } elseif ($typeSlug !== null) {
            $count = $this->resyncType($typeSlug, $withWebhooks);
        } else {
            $count = $this->resyncEverything($withWebhooks);
        }

        $effects = $withWebhooks
            ? 'projection + cache + search + webhooks'
            : 'projection + cache + search';
        $this->success(sprintf('Resynced %d published entr%s (%s).', $count, $count === 1 ? 'y' : 'ies', $effects));

        return self::SUCCESS;
    }

    /**
     * Re-drive every published pin of a single entry (one per locale it is published in).
     */
    private function resyncEntry(string $entryUuid, bool $withWebhooks): int
    {
        $pins = $this->delivery()->publishedPinsForEntry($entryUuid);
        if ($pins === []) {
            $this->warning(sprintf(
                'No published entry found for %s (draft-only or archived entries are skipped).',
                $entryUuid,
            ));
            return 0;
        }
        foreach ($pins as $pin) {
            $this->reDrive(
                new EntryPublished($pin['entry'], $pin['type'], $pin['locale'], $pin['version'], 'resync'),
                $withWebhooks,
            );
        }
        return count($pins);
    }

    /**
     * Re-drive every published entry of one content type (across all of its published locales).
     */
    private function resyncType(string $slug, bool $withWebhooks): int
    {
        $type = $this->types()->findBySlug($slug);
        if ($type === null) {
            $this->error(sprintf('Unknown content type: %s', $slug));
            return 0;
        }
        return $this->resyncTypeUuid((string) $type['uuid'], $withWebhooks);
    }

    /**
     * Re-drive every published entry across every content type.
     */
    private function resyncEverything(bool $withWebhooks): int
    {
        $total = 0;
        foreach ($this->types()->all() as $type) {
            $total += $this->resyncTypeUuid((string) $type['uuid'], $withWebhooks);
        }
        return $total;
    }

    /**
     * Page (keyset) through every published entry of a content-type UUID, per locale, in
     * bounded batches — never loading the whole published set into memory at once.
     */
    private function resyncTypeUuid(string $typeUuid, bool $withWebhooks): int
    {
        $delivery = $this->delivery();
        $order = SortCompiler::defaultOrder();
        $count = 0;

        foreach ($delivery->publishedLocalesForType($typeUuid) as $locale) {
            $cursor = null;
            do {
                $rows = $delivery->listPublished($typeUuid, $locale, self::BATCH, null, $order, $cursor);
                foreach ($rows as $row) {
                    $this->reDrive(
                        new EntryPublished(
                            (string) $row['entry_uuid'],
                            $typeUuid,
                            (string) $row['locale'],
                            (int) $row['version'],
                            'resync',
                        ),
                        $withWebhooks,
                    );
                    $count++;
                }
                // Advance the keyset cursor past the last row; stop on a short page.
                $last = $rows[count($rows) - 1] ?? null;
                $cursor = ($last !== null && count($rows) === self::BATCH)
                    ? $delivery->cursorFor($last, $order)
                    : null;
            } while ($cursor !== null);
        }

        return $count;
    }

    /**
     * Invoke the idempotent effect listeners for one publish event. Projection rebuild +
     * cache invalidation + CDN purge + search reindex always run (projection first, so
     * the purge sees current rows); webhook re-dispatch only when opted in.
     */
    private function reDrive(EntryPublished $event, bool $withWebhooks): void
    {
        ($this->getService(ProjectPublishedReferencesListener::class))($event);
        ($this->getService(InvalidateCacheTagsListener::class))($event);
        ($this->getService(PurgeCdnListener::class))($event);
        ($this->getService(ReindexSearchListener::class))($event);

        if ($withWebhooks) {
            ($this->getService(DispatchWebhookListener::class))($event);
        }
    }

    private function delivery(): DeliveryRepository
    {
        return $this->getService(DeliveryRepository::class);
    }

    private function types(): ContentTypeRepository
    {
        return $this->getService(ContentTypeRepository::class);
    }
}
