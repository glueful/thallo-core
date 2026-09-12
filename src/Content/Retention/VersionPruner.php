<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Retention;

use Glueful\Database\Connection;
use Psr\Log\LoggerInterface;
use Thallo\Contracts\Tenancy\WriteBarrier;

/**
 * Deletes out-of-policy, non-pinned rows from entry_versions per (entry, locale)
 * lineage. The delete-time NOT EXISTS guard is the correctness barrier: a row
 * pinned after selection but before deletion is spared by the DELETE itself.
 *
 * SYSTEM PATH (tenancy): lineages()/computeDeletable()/deleteGuarded() run raw PDO with NO tenant
 * predicate — they scan and delete across all tenants in one background pass. This is correct under
 * the shared-DB model precisely because every lineage is keyed on globally-unique nano-id uuids
 * (entry_uuid + version uuid): a lineage belongs to exactly one tenant, so a global scan prunes each
 * tenant's history independently and can never delete across the boundary. No tenant context is
 * required, and adding a predicate here would only narrow (and could mis-scope) an already-correct
 * global sweep.
 */
final class VersionPruner
{
    private const DELETE_BATCH = 500;

    public function __construct(
        private readonly Connection $db,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?WriteBarrier $barrier = null,
    ) {
    }

    public function prune(RetentionPolicy $policy, bool $dryRun = false): PruneReport
    {
        $report = new PruneReport();

        if (!$policy->isEnabled()) {
            return $report;
        }

        foreach ($this->lineages() as $lineage) {
            $selection = $this->computeDeletable($lineage['entry_uuid'], $lineage['locale'], $policy);
            $deleted = $dryRun
                ? count($selection['deletable'])
                : $this->deleteGuarded($selection['deletable']);

            $report->recordLineage($deleted, $selection['retained'], $selection['pinnedSkipped']);
        }

        $this->logger?->info('thallo.versions.pruned', array_merge($report->toArray(), [
            'keep' => $policy->keep,
            'max_age_days' => $policy->maxAgeDays,
            'dry_run' => $dryRun,
        ]));

        return $report;
    }

    /**
     * Select deletable UUIDs for one lineage. A row survives if it is inside keep-N,
     * inside max-age, or currently pinned. Returned pinnedSkipped counts out-of-policy
     * rows that survived only because they are pinned.
     *
     * @return array{deletable:list<string>,retained:int,pinnedSkipped:int}
     */
    public function computeDeletable(string $entry, string $locale, RetentionPolicy $policy): array
    {
        $sql = <<<'SQL'
            WITH ranked AS (
                SELECT
                    ev.uuid,
                    ev.created_at,
                    ROW_NUMBER() OVER (ORDER BY ev.version DESC) AS rnk,
                    (p.version_uuid IS NOT NULL)::int AS is_pinned
                FROM entry_versions ev
                LEFT JOIN entry_publications p ON p.version_uuid = ev.uuid
                WHERE ev.entry_uuid = :entry AND ev.locale = :locale
            )
            SELECT
                uuid,
                is_pinned,
                (
                    CAST(:keep_check AS integer) IS NOT NULL
                    AND rnk <= CAST(:keep_rank AS integer)
                )::int AS keep_survivor,
                (
                    CAST(:age_check AS integer) IS NOT NULL
                    AND created_at >= now() - (CAST(:age_days AS integer) * interval '1 day')
                )::int AS age_survivor
            FROM ranked
        SQL;

        $stmt = $this->db->getPDO()->prepare($sql);
        $stmt->execute([
            'entry' => $entry,
            'locale' => $locale,
            'keep_check' => $policy->keep,
            'keep_rank' => $policy->keep,
            'age_check' => $policy->maxAgeDays,
            'age_days' => $policy->maxAgeDays,
        ]);

        $deletable = [];
        $retained = 0;
        $pinnedSkipped = 0;

        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if ((int) $row['keep_survivor'] === 1 || (int) $row['age_survivor'] === 1) {
                $retained++;
                continue;
            }

            if ((int) $row['is_pinned'] === 1) {
                $pinnedSkipped++;
                continue;
            }

            $deletable[] = (string) $row['uuid'];
        }

        return [
            'deletable' => $deletable,
            'retained' => $retained,
            'pinnedSkipped' => $pinnedSkipped,
        ];
    }

    /**
     * Delete selected UUIDs with a delete-time publication guard.
     *
     * @param list<string> $uuids
     */
    public function deleteGuarded(array $uuids): int
    {
        if ($uuids === []) {
            return 0;
        }

        $deleted = 0;
        foreach (array_chunk($uuids, self::DELETE_BATCH) as $chunk) {
            $write = function () use ($chunk): int {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                $stmt = $this->db->getPDO()->prepare(
                    "DELETE FROM entry_versions
                     WHERE uuid IN ({$placeholders})
                       AND NOT EXISTS (
                           SELECT 1 FROM entry_publications p
                           WHERE p.version_uuid = entry_versions.uuid
                       )"
                );
                $stmt->execute($chunk);
                return $stmt->rowCount();
            };
            $deleted += (int) ($this->barrier !== null
                ? $this->barrier->runWritable($write)
                : $write());
        }

        return $deleted;
    }

    /** @return list<array{entry_uuid:string,locale:string}> */
    private function lineages(): array
    {
        $stmt = $this->db->getPDO()->query(
            'SELECT DISTINCT entry_uuid, locale FROM entry_versions ORDER BY entry_uuid, locale'
        );

        return array_map(
            static fn (array $row): array => [
                'entry_uuid' => (string) $row['entry_uuid'],
                'locale' => (string) $row['locale'],
            ],
            $stmt->fetchAll(\PDO::FETCH_ASSOC),
        );
    }
}
