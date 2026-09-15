<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Style\Classes;

use Glueful\Database\Connection;
use Glueful\Events\EventService;
use Glueful\Helpers\Utils;
use Thallo\Contracts\Style\StyleClassProvider;
use Thallo\Contracts\Style\StyleClassSaved;
use Thallo\Core\Content\Style\SiteStyleGeneration;

/**
 * Style class records (visual builder spec §4.1, §4.3, §4.5). Every mutation goes through
 * `write()`, which runs the row write and the site style generation's increment on this one
 * connection in one transaction — the atomicity is structural, not conventional — and refreshes
 * the request's snapshot afterwards. Ids are stable; names are site-unique case-insensitively
 * through `name_key`; `version` is the optimistic-concurrency token bumped by every write.
 */
final class StyleClassRepository
{
    private const NAME_MAX = 120;

    public function __construct(
        private readonly Connection $db,
        private readonly SiteStyleGeneration $generation,
        private readonly ?StyleClassProvider $provider = null,
        private readonly ?EventService $events = null,
    ) {
    }

    /**
     * @template T
     * @param callable(Connection): T $fn
     * @return T
     */
    public function write(callable $fn): mixed
    {
        $this->generation->ensureRow();
        $generation = 0;
        try {
            $result = $this->db->transaction(function () use ($fn, &$generation): mixed {
                $result = $fn($this->db);
                $generation = $this->generation->incrementWithin($this->db);
                return $result;
            });
        } finally {
            $this->provider?->refresh();
        }
        // After the commit only: a rolled-back write is not a class write.
        $id = is_array($result) && isset($result['id']) ? (string) $result['id'] : '';
        $this->events?->dispatch(new StyleClassSaved($id, $generation));
        return $result;
    }

    /**
     * @param array{name: string, description?: string|null, style?: array<string,mixed>} $input
     * @return array<string,mixed>
     */
    public function create(array $input): array
    {
        $name = self::name($input['name'] ?? '');
        $id = Utils::generateNanoID();
        $now = gmdate('Y-m-d H:i:s');
        return $this->write(function (Connection $db) use ($id, $name, $input, $now): array {
            $this->assertNameFree($name, null);
            $db->table('style_classes')->insert([
                'id' => $id,
                'name' => $name,
                'name_key' => self::key($name),
                'description' => self::description($input['description'] ?? null),
                'style' => json_encode($input['style'] ?? [], JSON_THROW_ON_ERROR),
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            return $this->require($id);
        });
    }

    /**
     * @param array{name?: string, description?: string|null, style?: array<string,mixed>} $changes
     * @return array<string,mixed>
     */
    public function update(string $id, int $expectedVersion, array $changes): array
    {
        return $this->write(function (Connection $db) use ($id, $expectedVersion, $changes): array {
            $row = $this->require($id);
            $this->assertUnlocked($row);
            $data = [];
            if (array_key_exists('name', $changes)) {
                $name = self::name((string) $changes['name']);
                $this->assertNameFree($name, $id);
                $data['name'] = $name;
                $data['name_key'] = self::key($name);
            }
            if (array_key_exists('description', $changes)) {
                $data['description'] = self::description($changes['description']);
            }
            if (array_key_exists('style', $changes)) {
                $data['style'] = json_encode($changes['style'] ?? [], JSON_THROW_ON_ERROR);
            }
            $this->bump($db, $row, $expectedVersion, $data);
            return $this->require($id);
        });
    }

    /** @return array<string,mixed> */
    public function archive(string $id): array
    {
        return $this->write(function (Connection $db) use ($id): array {
            $row = $this->require($id);
            $this->assertUnlocked($row);
            $this->bump($db, $row, (int) $row['version'], ['archived_at' => gmdate('Y-m-d H:i:s')]);
            return $this->require($id);
        });
    }

    /** @return array<string,mixed> */
    public function lock(string $id, string $job): array
    {
        return $this->write(function (Connection $db) use ($id, $job): array {
            $row = $this->require($id);
            $this->assertUnlocked($row);
            // A write on the row: it serialises against a document write's reference guard (§4.5).
            $affected = $db->table('style_classes')
                ->where('id', '=', $id)
                ->whereNull('locked_by_job')
                ->update(['locked_by_job' => $job, 'version' => (int) $row['version'] + 1]);
            if ($affected < 1) {
                throw new StyleClassLocked($id, (string) ($this->require($id)['locked_by_job'] ?? ''));
            }
            return $this->require($id);
        });
    }

    /** @return array<string,mixed> */
    public function unlock(string $id): array
    {
        return $this->write(function (Connection $db) use ($id): array {
            $row = $this->require($id);
            $db->table('style_classes')->where('id', '=', $id)
                ->update(['locked_by_job' => null, 'version' => (int) $row['version'] + 1]);
            return $this->require($id);
        });
    }

    /** @return array<string,mixed>|null */
    public function find(string $id): ?array
    {
        $row = $this->db->table('style_classes')->where('id', '=', $id)->first();
        return $row === null ? null : self::hydrate($row);
    }

    /** @return list<array<string,mixed>> */
    public function all(bool $includeArchived = true): array
    {
        $query = $this->db->table('style_classes')->orderBy('name_key', 'ASC');
        if (!$includeArchived) {
            $query->whereNull('archived_at');
        }
        return array_map(self::hydrate(...), $query->get());
    }

    /** @return array<string,mixed> */
    private function require(string $id): array
    {
        return $this->find($id) ?? throw new StyleClassNotFound($id);
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $data
     */
    private function bump(Connection $db, array $row, int $expectedVersion, array $data): void
    {
        $affected = $db->table('style_classes')
            ->where('id', '=', $row['id'])
            ->where('version', '=', $expectedVersion)
            ->update($data + ['version' => $expectedVersion + 1, 'updated_at' => gmdate('Y-m-d H:i:s')]);
        if ($affected < 1) {
            $current = (int) $this->require((string) $row['id'])['version'];
            throw new StyleClassVersionConflict((string) $row['id'], $current);
        }
    }

    private function assertNameFree(string $name, ?string $exceptId): void
    {
        $query = $this->db->table('style_classes')->where('name_key', '=', self::key($name));
        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }
        if ($query->first() !== null) {
            throw new StyleClassNameTaken($name);
        }
    }

    /** @param array<string,mixed> $row */
    private function assertUnlocked(array $row): void
    {
        if (($row['locked_by_job'] ?? null) !== null) {
            throw new StyleClassLocked((string) $row['id'], (string) $row['locked_by_job']);
        }
    }

    private static function name(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > self::NAME_MAX) {
            throw new \InvalidArgumentException('a style class name is 1 to ' . self::NAME_MAX . ' characters');
        }
        return $name;
    }

    private static function key(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    private static function description(mixed $description): ?string
    {
        if ($description === null) {
            return null;
        }
        $description = trim((string) $description);
        return $description === '' ? null : $description;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function hydrate(array $row): array
    {
        $style = $row['style'] ?? [];
        if (is_string($style)) {
            $style = json_decode($style, true, 512, JSON_THROW_ON_ERROR);
        }
        return [
            'id' => (string) $row['id'],
            'name' => (string) $row['name'],
            'description' => $row['description'] ?? null,
            'style' => is_array($style) ? $style : [],
            'version' => (int) $row['version'],
            'archived' => ($row['archived_at'] ?? null) !== null,
            'archived_at' => $row['archived_at'] ?? null,
            'locked_by_job' => $row['locked_by_job'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }
}
