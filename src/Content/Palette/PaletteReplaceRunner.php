<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Palette;

use Glueful\Database\Connection;
use Glueful\Events\EventService;
use Glueful\Extensions\Audit\Contracts\AuditRecorderInterface;
use Glueful\Extensions\Audit\Support\AuditEntry;
use Thallo\Contracts\Settings\ThemeAppearanceChanged;
use Thallo\Contracts\Style\Palette;
use Thallo\Core\Content\Blocks\Sources\BlockDocumentSource;
use Thallo\Core\Content\Blocks\Sources\DocumentRef;
use Thallo\Core\Content\Style\Classes\StyleClassLocked;
use Thallo\Core\Settings\GeneralSettings;

/**
 * Runs one replacement (custom palette spec §4.4). Each pass enumerates the current documents still
 * naming the slot and rewrites each through the palette fence, forced — under the row, against the
 * state it holds, refused once the job is no longer active — retrying a document that moved on.
 * A pass that finds none takes the row, recounts what blocks the slot and, when nothing does,
 * completes the job and clears the slot in that one transaction; five passes without that fail the
 * job, which keeps the slot configured and resumable.
 */
final class PaletteReplaceRunner
{
    public const MAX_PASSES = 5;
    private const ATTEMPTS = 3;

    public function __construct(
        private readonly Connection $db,
        private readonly PaletteJobRepository $jobs,
        private readonly PaletteDocumentSources $sources,
        private readonly PaletteFence $fence,
        private readonly PaletteState $state,
        private readonly PaletteNormalizer $normalizer,
        private readonly ColorTokenWalker $walker,
        private readonly BrandColorUsage $usage,
        private readonly GeneralSettings $settings,
        private readonly PaletteCacheEffects $effects,
        private readonly ?EventService $events = null,
        private readonly ?AuditRecorderInterface $audit = null,
    ) {
    }

    /** The same runner over another registry (proofs only: a source double). */
    public function withSources(PaletteDocumentSources $sources): self
    {
        return new self(
            $this->db,
            $this->jobs,
            $sources,
            $this->fence,
            $this->state,
            $this->normalizer,
            $this->walker,
            $this->usage,
            $this->settings,
            $this->effects,
            $this->events,
            $this->audit,
        );
    }

    /** @return array{status: string, passes: int, done: int, failed: int} */
    public function run(string $jobId): array
    {
        $job = $this->jobs->find($jobId) ?? throw new \RuntimeException("palette job {$jobId} not found");
        /** @var array<string,int> $counts */
        $counts = [];
        for ($pass = 1; $pass <= self::MAX_PASSES; $pass++) {
            if (!$this->jobs->isActive($jobId)) {
                return $this->summary($jobId);
            }
            $this->jobs->touchHeartbeat($jobId);
            $naming = $this->naming($job->slot);
            $this->jobs->beginPass($jobId, count($naming));
            if ($naming === []) {
                $outcome = $this->complete($job, $counts);
                if ($outcome !== 'again') {
                    return $this->summary($jobId);
                }
                continue;
            }
            foreach ($naming as [$source, $ref]) {
                if ($this->process($job, $source, $ref, $counts) === 'fenced') {
                    return $this->summary($jobId);
                }
            }
        }
        $this->fail($job);
        return $this->summary($jobId);
    }

    /**
     * @return list<array{0: BlockDocumentSource, 1: DocumentRef}> the current documents naming the slot
     */
    private function naming(int $slot): array
    {
        $out = [];
        $this->sources->each(function (BlockDocumentSource $source, DocumentRef $ref) use ($slot, &$out): void {
            if ($this->names($ref, $slot)) {
                $out[] = [$source, $ref];
            }
        });
        return $out;
    }

    private function names(DocumentRef $ref, int $slot): bool
    {
        $kind = PaletteDocumentSources::kindOf($ref->sourceType);
        foreach ($this->walker->tokens($kind, $ref->fields, $ref->schema) as $token) {
            if (Palette::slotOf($token) === $slot) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string,int> $counts
     * @return 'written'|'failed'|'fenced'|'gone'
     */
    private function process(PaletteJob $job, BlockDocumentSource $source, DocumentRef $ref, array &$counts): string
    {
        $kind = PaletteDocumentSources::kindOf($ref->sourceType);
        $current = $ref;
        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            try {
                $written = $this->fence->write(
                    fn (PaletteSnapshot $s): Normalized => $this->normalizer->normalize(
                        $kind,
                        $current->fields,
                        $s,
                        $this->normalizer->basisOf($kind, $current->schema, $current->fields),
                        $current->schema,
                    ),
                    function (array $doc) use ($job, $source, $current): bool {
                        if (!$this->jobs->isActive($job->id)) {
                            throw new JobFenced($job->id); // cancelled or finished: write nothing
                        }
                        return $doc === $current->fields ? true : $source->persist($current, $doc, $job->createdBy);
                    },
                    force: true,
                );
            } catch (JobFenced) {
                return 'fenced';
            } catch (PaletteRefusal) {
                $this->failure($job, $current, "a text colour on Brand {$job->slot} arrived after the replacement "
                    . 'started; cancel and choose a text colour');
                return 'failed';
            } catch (StyleClassLocked) {
                $this->failure($job, $current, 'a style class job holds this class; retried on the next pass');
                return 'failed';
            } catch (\Throwable $e) {
                $this->failure($job, $current, $e->getMessage());
                return 'failed';
            }
            if ($written) {
                $this->jobs->incrementDone($job->id);
                $counts[$current->sourceType] = ($counts[$current->sourceType] ?? 0) + 1;
                $rewritten = $current;
                $this->db->afterCommit(fn () => $this->effects->afterWrite($rewritten));
                return 'written';
            }
            $fresh = $this->refetch($source, $current);
            if ($fresh === null) {
                return 'gone';
            }
            $current = $fresh;
        }
        $this->failure($job, $current, 'document changed concurrently; retried on the next pass');
        return 'failed';
    }

    /** The document again, as its source reads it now (matched by source id and locale). */
    private function refetch(BlockDocumentSource $source, DocumentRef $ref): ?DocumentRef
    {
        $found = null;
        $source->each(static function (DocumentRef $candidate) use ($ref, &$found): void {
            if ($candidate->sourceId === $ref->sourceId && $candidate->locale === $ref->locale) {
                $found = $candidate;
            }
        });
        return $found;
    }

    /**
     * Nothing named the slot: under the row, recount what blocks it; none → complete and clear.
     *
     * @param array<string,int> $counts
     * @return 'completed'|'again'|'stopped'
     */
    private function complete(PaletteJob $job, array $counts): string
    {
        $name = null;
        try {
            $outcome = $this->fence->within(function () use ($job, $counts, &$name): string {
                $held = $this->state->lock();
                if (($this->usage->of($job->slot)['blocking']['total'] ?? 0) > 0) {
                    return 'again';
                }
                $generation = $this->state->bump();
                if (!$this->jobs->transition($job->id, 'completed', $generation)) {
                    throw new JobFenced($job->id); // cancelled or finished meanwhile: roll the bump back
                }
                $name = $held->palette->brand($job->slot)?->name ?? "Brand {$job->slot}";
                $this->settings->save(['theme_brand_' . $job->slot => '']);
                $this->jobs->recordCounts($job->id, $counts);
                return 'completed';
            });
        } catch (JobFenced) {
            return 'stopped';
        }
        if ($outcome === 'completed') {
            $this->db->afterCommit(function () use ($job, $name): void {
                $this->audit?->record(new AuditEntry(
                    occurredAt: microtime(true),
                    action: 'palette.brand.replaced',
                    category: 'content',
                    actorUuid: $job->createdBy,
                    targetType: 'palette_slot',
                    targetUuid: 'brand-' . $job->slot,
                    targetLabel: $name,
                    context: ['to' => $job->to, 'contrast_to' => $job->contrastTo, 'job' => $job->id],
                ));
                $this->events?->dispatch(new ThemeAppearanceChanged(
                    $this->settings->themeAccent(),
                    $this->settings->themeNeutral(),
                ));
            });
        }
        return $outcome;
    }

    /**
     * Five passes without completing: fail the job under the row. When another worker completed it
     * or a cancel committed, the transition is refused and nothing is changed.
     */
    private function fail(PaletteJob $job): void
    {
        $this->fence->within(function () use ($job): void {
            $this->state->lock();
            if (!$this->jobs->transition($job->id, 'failed')) {
                return;
            }
            foreach ($this->naming($job->slot) as [, $ref]) {
                $this->failure($job, $ref, "still names Brand {$job->slot}");
            }
        });
    }

    private function failure(PaletteJob $job, DocumentRef $ref, string $reason): void
    {
        $this->jobs->recordFailure($job->id, $ref->sourceType, $ref->sourceId, $ref->locale, $reason);
    }

    /** @return array{status: string, passes: int, done: int, failed: int} */
    private function summary(string $jobId): array
    {
        $job = $this->jobs->find($jobId);
        return [
            'status' => $job?->status ?? 'unknown',
            'passes' => $job?->passes ?? 0,
            'done' => $job?->done ?? 0,
            'failed' => $job?->failed ?? 0,
        ];
    }
}
