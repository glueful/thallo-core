<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Style\Classes;

use Glueful\Database\Connection;
use Thallo\Contracts\Style\StyleClassProvider;
use Thallo\Contracts\Style\StyleClassSnapshot;
use Thallo\Core\Content\Style\SiteStyleGeneration;

/**
 * The request's style class snapshot (visual builder spec §4.3), loaded once and memoised until
 * `refresh()`. A load is one read transaction: the generation, every class row of the site, the
 * generation again. Glueful runs on SQLite, MySQL and PostgreSQL through one query builder that
 * exposes neither isolation levels nor row locks; MySQL's repeatable read and SQLite's read
 * transactions already give a consistent snapshot, PostgreSQL's read committed does not, so the
 * two generation reads are the guarantee on every engine: a snapshot is returned only when they
 * agree, a disagreement reloads, and exhaustion is a hard failure — never a stale fallback.
 * `reference_guard` is a lock token, never part of a snapshot.
 */
class EngineStyleClassProvider implements StyleClassProvider
{
    public const ATTEMPTS = 3;

    private ?StyleClassSnapshot $snapshot = null;

    public function __construct(
        private readonly Connection $db,
        private readonly SiteStyleGeneration $generation,
    ) {
    }

    public function snapshot(): StyleClassSnapshot
    {
        return $this->snapshot ??= $this->load();
    }

    public function refresh(): void
    {
        $this->snapshot = null;
    }

    private function load(): StyleClassSnapshot
    {
        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            [$before, $rows, $after] = $this->db->transaction(function (): array {
                $before = $this->readGeneration();
                $rows = $this->loadRows();
                return [$before, $rows, $this->readGeneration()];
            });
            if ($before === $after) {
                return new StyleClassSnapshot($before, $rows);
            }
        }
        throw new StyleClassSnapshotUnstable(
            'the style class generation changed during every one of ' . self::ATTEMPTS . ' snapshot loads',
        );
    }

    protected function readGeneration(): int
    {
        return $this->generation->current();
    }

    /** @return array<string, array{id: string, name: string, style: array<string,mixed>, archived: bool}> */
    protected function loadRows(): array
    {
        $rows = [];
        $columns = ['id', 'name', 'style', 'archived_at'];
        foreach ($this->db->table('style_classes')->select($columns)->orderBy('name_key', 'ASC')->get() as $row) {
            $class = StyleClassRepository::hydrate($row + ['version' => 0]);
            $rows[$class['id']] = [
                'id' => $class['id'],
                'name' => $class['name'],
                'style' => $class['style'],
                'archived' => $class['archived'],
            ];
        }
        return $rows;
    }
}
