<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Schema\Migration;

use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\MigrationRepository;

final class SchemaProjector
{
    /** @var array<string,list<array<string,mixed>>> */
    private array $chainCache = [];

    public function __construct(
        private readonly MigrationRepository $migrations,
        private readonly ContentTypeRepository $types,
    ) {
    }

    /**
     * @param array<string,mixed> $fields
     * @return array<string,mixed>
     */
    public function project(string $contentTypeUuid, int $fromSchemaVersion, array $fields): array
    {
        $type = $this->types->findByUuid($contentTypeUuid);
        if ($type === null) {
            return $fields;
        }

        $currentVersion = (int) $type['schema_version'];
        if ($fromSchemaVersion >= $currentVersion) {
            return $fields;
        }

        foreach ($this->chain($contentTypeUuid, $fromSchemaVersion, $currentVersion) as $migration) {
            $fields = MigrationOpSet::fromArray($migration['ops'])->applyForProjection($fields);
        }

        return $fields;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function chain(string $contentTypeUuid, int $fromSchemaVersion, int $currentVersion): array
    {
        $key = $contentTypeUuid . ':' . $currentVersion . ':' . $fromSchemaVersion;
        if (array_key_exists($key, $this->chainCache)) {
            return $this->chainCache[$key];
        }

        return $this->chainCache[$key] = array_values(array_filter(
            $this->migrations->chainFor($contentTypeUuid, $fromSchemaVersion),
            static fn (array $row): bool => (int) $row['to_version'] <= $currentVersion
        ));
    }
}
