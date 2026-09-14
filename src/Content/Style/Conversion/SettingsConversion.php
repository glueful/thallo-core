<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Style\Conversion;

use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Content\Blocks\Migration\BlockMigrationRepository;
use Thallo\Core\Content\Blocks\Sources\BlockDocumentSource;
use Thallo\Core\Content\Blocks\Sources\BlockDocumentSources;
use Thallo\Core\Content\Blocks\Sources\DocumentRef;
use Thallo\Core\Content\Blocks\Sources\EntryDraftsSource;
use Thallo\Core\Content\Blocks\Sources\EntryVersionsSource;
use Thallo\Core\Content\Blocks\Sources\RegionsSource;

/**
 * The conversion run (visual builder spec §7.3): evaluate every block-bearing document — drafts,
 * every retained version, regions — against its pending stages (the dry run), then persist each
 * converted document atomically only while it is still what was read. A live run refuses while
 * any diagnostic is unresolved, and runs only while no block-type migration is in progress.
 */
final class SettingsConversion
{
    public function __construct(
        private readonly BlockDocumentSources $sources,
        private readonly Converter $converter,
        private readonly BlockMigrationRepository $migrations,
        private readonly ConversionStages $stages,
        private readonly ?BlockTypeRepository $blockTypes = null,
    ) {
    }

    /**
     * @return array{
     *   report: DiagnosticsReport,
     *   documents: list<array{source: BlockDocumentSource, ref: DocumentRef, converted: ConvertedDocument}>,
     *   unresolved: int, pending: int
     * }
     */
    public function evaluate(DecisionsFile $decisions): array
    {
        $report = new DiagnosticsReport();
        $documents = [];
        $unresolved = 0;
        $this->sources->only(EntryDraftsSource::ID, EntryVersionsSource::ID, RegionsSource::ID)->each(
            function (
                BlockDocumentSource $source,
                DocumentRef $ref,
            ) use (
                $decisions,
                $report,
                &$documents,
                &$unresolved,
            ): void {
                $pending = $this->stages->pending($ref->fields);
                if ($pending === []) {
                    return;
                }
                $converted = $this->converter->convert($ref, $pending, $decisions, $report);
                $unresolved += $converted->unresolved;
                $documents[] = ['source' => $source, 'ref' => $ref, 'converted' => $converted];
            },
        );
        return [
            'report' => $report,
            'documents' => $documents,
            'unresolved' => $unresolved,
            'pending' => count($documents),
        ];
    }

    /** A block-type migration in progress owns the block trees: the converter waits. */
    public function blockedBy(): ?string
    {
        $active = $this->migrations->activeAny();
        return $active === [] ? null : (string) ($active[0]['slug'] ?? 'a block type');
    }

    /**
     * Persist an evaluation with no unresolved diagnostics. Each document is written atomically
     * (stamp and converted tree together) only while it is still at the revision it was read at.
     *
     * @param array{
     *   documents: list<array{source: BlockDocumentSource, ref: DocumentRef, converted: ConvertedDocument}>,
     *   unresolved: int
     * } $evaluation
     * @return array{converted: int, unchanged: int, changed: list<string>} `changed` = documents that moved on
     */
    public function apply(array $evaluation, ?string $actor = null): array
    {
        if ($evaluation['unresolved'] > 0) {
            throw new \LogicException('refusing to apply a conversion with unresolved diagnostics');
        }
        $converted = 0;
        $unchanged = 0;
        $changed = [];
        // The rehearsal's interruption hook (plan A4.7): abort once every document of the named
        // source is written, leaving the run half done — a rerun must land on the same state.
        $abortAfter = getenv('THALLO_CONVERT_ABORT_AFTER_SOURCE') ?: null;
        $lastSource = null;
        foreach ($evaluation['documents'] as $document) {
            $sourceId = $document['source']->id();
            if ($abortAfter !== null && $lastSource === $abortAfter && $sourceId !== $abortAfter) {
                throw new \RuntimeException("conversion aborted after source {$abortAfter} (rehearsal)");
            }
            $lastSource = $sourceId;
            if (!$document['converted']->changed) {
                $unchanged++;
                continue;
            }
            if ($document['source']->persist($document['ref'], $document['converted']->fields, $actor)) {
                $converted++;
            } else {
                $changed[] = $document['ref']->identity();
            }
        }
        if ($changed === []) {
            $this->retireFields();
        }
        return ['converted' => $converted, 'unchanged' => $unchanged, 'changed' => $changed];
    }

    /**
     * Once every document is converted, the legacy fields leave the block type rows too, so
     * the editor stops offering them (the starter definitions no longer carry them either).
     */
    private function retireFields(): void
    {
        if ($this->blockTypes === null) {
            return;
        }
        foreach ($this->stages->all() as $stage) {
            foreach ($stage->retiredFields as $slug => $fields) {
                $row = $this->blockTypes->findBySlug($slug);
                if ($row === null) {
                    continue;
                }
                $schema = array_values(array_filter(
                    (array) $row['schema'],
                    static fn (array $f): bool => !in_array($f['name'] ?? null, $fields, true),
                ));
                if (count($schema) === count((array) $row['schema'])) {
                    continue;
                }
                // The additive-only guard exists so a removed field never silently strips
                // stored data; every document was just converted, so this is the migration
                // flow's guard-exempt path with the same justification.
                $this->blockTypes->applyMigratedSchema((string) $row['uuid'], $schema);
            }
        }
    }
}
