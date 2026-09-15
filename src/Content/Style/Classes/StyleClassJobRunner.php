<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Style\Classes;

use Thallo\Contracts\Delivery\RenderedPageCachePurge;
use Thallo\Contracts\Style\BlockStyleRegistry;
use Thallo\Contracts\Style\DetachTransformation;
use Thallo\Contracts\Style\StyleClassProvider;
use Thallo\Contracts\Tenancy\WriteBarrier;
use Thallo\Core\Content\Blocks\Sources\BlockDocumentSource;
use Thallo\Core\Content\Blocks\Sources\BlockDocumentSources;
use Thallo\Core\Content\Blocks\Sources\DocumentRef;
use Thallo\Core\Content\Blocks\Sources\RegionsSource;

/**
 * Detach-everywhere and remove-everywhere (visual builder spec §4.5), pass-based. A pass visits
 * every document that references the class: `detach` rewrites each referencing block's
 * `settings.style` through the pure detach transformation with the block's capabilities and
 * removes the reference; `remove` removes the reference only (not appearance-preserving). Each
 * document persists through its source's conditional write and, on success, is purged the way an
 * ordinary save is; a refused write is recorded and retried on the next pass. Completion is a
 * pass that finds no remaining reference and no unresolved failure; at most five passes, then
 * `failed` naming every document still referencing the class. Completion unlocks the class — a
 * class write, the only place the generation moves; document rewrites never touch it.
 */
final class StyleClassJobRunner
{
    public const MAX_PASSES = 5;

    public function __construct(
        private readonly StyleClassJobRepository $jobs,
        private readonly StyleClassRepository $classes,
        private readonly StyleClassProvider $provider,
        private readonly BlockDocumentSources $sources,
        private readonly BlockStyleRegistry $registry,
        private readonly ?RenderedPageCachePurge $purge = null,
        private readonly ?WriteBarrier $barrier = null,
    ) {
    }

    /** @return array{status: string, passes: int, done: int, failed: int} */
    public function run(string $jobId): array
    {
        $this->barrier?->assertWritable();
        $job = $this->jobs->find($jobId) ?? throw new \RuntimeException("style class job {$jobId} not found");
        if ($job['status'] !== 'running') {
            return $this->summary($job);
        }
        $class = $this->classes->find($job['class_id']);
        if ($class === null) {
            $this->jobs->recordFailure($jobId, 'class', $job['class_id'], null, 'the style class no longer exists');
            $this->jobs->finish($jobId, 'failed');
            return $this->summary($this->jobs->find($jobId) ?? $job);
        }
        if ((int) $class['version'] !== $job['class_version']) {
            $this->jobs->recordFailure($jobId, 'class', $class['id'], null, 'class changed since the job was queued');
            $this->jobs->finish($jobId, 'failed');
            $this->classes->unlock($class['id']);
            return $this->summary($this->jobs->find($jobId) ?? $job);
        }
        $status = 'failed';
        for ($pass = 1; $pass <= self::MAX_PASSES; $pass++) {
            $referencing = $this->referencing($class['id']);
            $this->jobs->beginPass($jobId, count($referencing));
            if ($referencing === []) {
                $status = 'completed';
                break;
            }
            foreach ($referencing as [$source, $ref]) {
                $this->process($jobId, $job['kind'], $class['id'], $source, $ref);
            }
        }
        if ($status === 'failed') {
            foreach ($this->referencing($class['id']) as [$source, $ref]) {
                $reason = 'still references the class';
                $this->jobs->recordFailure($jobId, $ref->sourceType, $ref->sourceId, $ref->locale, $reason);
            }
        }
        $this->jobs->finish($jobId, $status);
        $this->classes->unlock($class['id']);
        return $this->summary($this->jobs->find($jobId) ?? $job);
    }

    /** @return list<array{0: BlockDocumentSource, 1: DocumentRef}> */
    private function referencing(string $classId): array
    {
        $out = [];
        $this->sources->each(function (BlockDocumentSource $source, DocumentRef $ref) use ($classId, &$out): void {
            if ($this->documentReferences($ref, $classId)) {
                $out[] = [$source, $ref];
            }
        });
        return $out;
    }

    private function process(
        string $jobId,
        string $kind,
        string $classId,
        BlockDocumentSource $source,
        DocumentRef $ref,
    ): void {
        try {
            $fields = $ref->fields;
            $changed = false;
            foreach ($ref->schema->fields() as $field) {
                if ($field->type !== 'blocks') {
                    continue;
                }
                $list = $fields[$field->name] ?? null;
                if (!is_array($list)) {
                    continue;
                }
                $fields[$field->name] = $this->rewrite($list, $kind, $classId, $changed);
            }
            if (!$changed) {
                return;
            }
            if (!$source->persist($ref, $fields)) {
                $reason = 'document changed concurrently; retried on the next pass';
                $this->jobs->recordFailure($jobId, $ref->sourceType, $ref->sourceId, $ref->locale, $reason);
                return;
            }
            $this->jobs->incrementDone($jobId);
            $this->purgeFor($ref);
        } catch (\Throwable $e) {
            $this->jobs->recordFailure($jobId, $ref->sourceType, $ref->sourceId, $ref->locale, $e->getMessage());
        }
    }

    /**
     * @param list<array<string,mixed>> $blocks
     * @return list<array<string,mixed>>
     */
    private function rewrite(array $blocks, string $kind, string $classId, bool &$changed): array
    {
        $snapshot = $this->provider->snapshot();
        foreach ($blocks as $i => $block) {
            if (!is_array($block) || !is_string($block['type'] ?? null)) {
                continue;
            }
            $classes = $block['settings']['classes'] ?? null;
            if (is_array($classes) && in_array($classId, $classes, true)) {
                if ($kind === 'detach') {
                    $style = is_array($block['settings']['style'] ?? null) ? $block['settings']['style'] : [];
                    $refs = $snapshot->refsFor(array_values(array_filter($classes, 'is_string')));
                    $caps = $this->registry->capabilitiesFor($block['type']);
                    $style = DetachTransformation::detach($refs, $style, $classId, $caps);
                    if ($style === []) {
                        unset($block['settings']['style']);
                    } else {
                        $block['settings']['style'] = $style;
                    }
                }
                $remaining = array_values(array_filter($classes, static fn ($id): bool => $id !== $classId));
                if ($remaining === []) {
                    unset($block['settings']['classes']);
                } else {
                    $block['settings']['classes'] = $remaining;
                }
                $changed = true;
            }
            foreach ($this->registry->regionsFor($block['type']) as $slot) {
                $child = $block['data'][$slot] ?? null;
                if (is_array($child)) {
                    $block['data'][$slot] = $this->rewrite($child, $kind, $classId, $changed);
                }
            }
            $blocks[$i] = $block;
        }
        return $blocks;
    }

    private function documentReferences(DocumentRef $ref, string $classId): bool
    {
        foreach ($ref->schema->fields() as $field) {
            if ($field->type === 'blocks' && $this->listReferences($ref->fields[$field->name] ?? null, $classId)) {
                return true;
            }
        }
        return false;
    }

    private function listReferences(mixed $list, string $classId): bool
    {
        if (!is_array($list)) {
            return false;
        }
        foreach ($list as $block) {
            if (!is_array($block) || !is_string($block['type'] ?? null)) {
                continue;
            }
            $classes = $block['settings']['classes'] ?? null;
            if (is_array($classes) && in_array($classId, $classes, true)) {
                return true;
            }
            foreach ($this->registry->regionsFor($block['type']) as $slot) {
                if ($this->listReferences($block['data'][$slot] ?? null, $classId)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** The purge an ordinary save of this document performs (the file driver purges everything). */
    private function purgeFor(DocumentRef $ref): void
    {
        if ($this->purge === null) {
            return;
        }
        if ($ref->sourceType === RegionsSource::ID) {
            $this->purge->purge(['thallo:render:page']);
            return;
        }
        $tags = ['thallo:entry:' . (string) ($ref->meta['entry_uuid'] ?? $ref->sourceId)];
        if (isset($ref->meta['content_type'])) {
            $tags[] = 'thallo:type:' . (string) $ref->meta['content_type'];
        }
        $this->purge->purge($tags);
    }

    /**
     * @param array<string,mixed> $job
     * @return array{status: string, passes: int, done: int, failed: int}
     */
    private function summary(array $job): array
    {
        return [
            'status' => (string) $job['status'],
            'passes' => (int) $job['passes'],
            'done' => (int) $job['work_items_done'],
            'failed' => (int) $job['work_items_failed'],
        ];
    }
}
