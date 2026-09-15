<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Style\Classes;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\TenantScope;
use Glueful\Helpers\Utils;
use Thallo\Contracts\Tenancy\WriteBarrier;

/** The `style_class_jobs` records (visual builder spec §4.5). */
final class StyleClassJobRepository
{
    public const KINDS = ['detach', 'remove'];

    public function __construct(
        private readonly Connection $db,
        private readonly ?ApplicationContext $context = null,
        private readonly ?CurrentTenantResolver $tenants = null,
        private readonly ?WriteBarrier $barrier = null,
    ) {
    }

    public function start(string $classId, int $classVersion, string $kind): string
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException("unknown style class job kind {$kind}");
        }
        $id = Utils::generateNanoID();
        $this->db->table('style_class_jobs')->insert([
            'id' => $id,
            'class_id' => $classId,
            'class_version' => $classVersion,
            'kind' => $kind,
            'status' => 'running',
            'passes' => 0,
            'work_items_total' => 0,
            'work_items_done' => 0,
            'work_items_failed' => 0,
            'failure_report' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return $id;
    }

    /** @return array<string,mixed>|null */
    public function find(string $id): ?array
    {
        $row = $this->db->table('style_class_jobs')->where('id', '=', $id)->first();
        return $row === null ? null : self::hydrate($row);
    }

    /** @return array<string,mixed>|null */
    public function activeFor(string $classId): ?array
    {
        $row = $this->db->table('style_class_jobs')
            ->where('class_id', '=', $classId)
            ->where('status', '=', 'running')
            ->orderBy('created_at', 'DESC')
            ->first();
        return $row === null ? null : self::hydrate($row);
    }

    public function beginPass(string $id, int $workItems): void
    {
        $row = $this->find($id) ?? throw new \RuntimeException("style class job {$id} not found");
        $this->db->table('style_class_jobs')->where('id', '=', $id)->update([
            'passes' => (int) $row['passes'] + 1,
            'work_items_total' => $workItems,
            'work_items_failed' => 0,
            'failure_report' => json_encode([], JSON_THROW_ON_ERROR),
        ]);
    }

    public function incrementDone(string $id): void
    {
        $tenant = TenantScope::current($this->tenants, $this->context);
        $scope = $tenant === null ? '' : ' AND tenant_uuid = :tenant';
        $params = ['id' => $id];
        if ($tenant !== null) {
            $params['tenant'] = $tenant;
        }
        $write = function () use ($scope, $params): bool {
            $stmt = $this->db->getPDO()->prepare(
                'UPDATE style_class_jobs SET work_items_done = work_items_done + 1 WHERE id = :id' . $scope
            );
            return $stmt->execute($params);
        };
        $this->barrier !== null ? $this->barrier->runWritable($write) : $write();
    }

    public function recordFailure(
        string $id,
        string $sourceType,
        string $sourceId,
        ?string $locale,
        string $reason,
    ): void {
        $row = $this->find($id);
        if ($row === null) {
            return;
        }
        $report = $row['failure_report'];
        $report[] = ['source' => $sourceType, 'id' => $sourceId, 'locale' => $locale, 'reason' => $reason];
        $this->db->table('style_class_jobs')->where('id', '=', $id)->update([
            'work_items_failed' => (int) $row['work_items_failed'] + 1,
            'failure_report' => json_encode($report, JSON_THROW_ON_ERROR),
        ]);
    }

    public function finish(string $id, string $status): void
    {
        if (!in_array($status, ['completed', 'failed'], true)) {
            throw new \InvalidArgumentException('a style class job finishes completed or failed');
        }
        $this->db->table('style_class_jobs')->where('id', '=', $id)->update([
            'status' => $status,
            'finished_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function hydrate(array $row): array
    {
        $report = $row['failure_report'] ?? [];
        if (is_string($report)) {
            $report = json_decode($report, true) ?? [];
        }
        return [
            'id' => (string) $row['id'],
            'class_id' => (string) $row['class_id'],
            'class_version' => (int) $row['class_version'],
            'kind' => (string) $row['kind'],
            'status' => (string) $row['status'],
            'passes' => (int) $row['passes'],
            'work_items_total' => (int) $row['work_items_total'],
            'work_items_done' => (int) $row['work_items_done'],
            'work_items_failed' => (int) $row['work_items_failed'],
            'failure_report' => is_array($report) ? $report : [],
            'created_at' => $row['created_at'] ?? null,
            'finished_at' => $row['finished_at'] ?? null,
        ];
    }
}
