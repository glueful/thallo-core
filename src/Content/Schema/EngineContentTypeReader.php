<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Schema;

use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Contracts\Schema\ContentSchemaReader;
use Thallo\Contracts\Schema\ContentTypeReader;

final class EngineContentTypeReader implements ContentTypeReader
{
    public function __construct(private readonly ContentTypeRepository $types)
    {
    }

    public function findUuidBySlug(string $slug): ?string
    {
        $row = $this->types->findBySlug($slug);
        return $row === null ? null : (string) $row['uuid'];
    }

    public function schemaFor(string $uuid): ?ContentSchemaReader
    {
        $row = $this->types->findByUuid($uuid);
        return $row === null ? null : ContentTypeSchema::fromArray($row['schema']);
    }

    public function isPublicDelivery(string $uuid): bool
    {
        $row = $this->types->findByUuid($uuid);
        return $row !== null && (bool) ($row['public_delivery'] ?? false);
    }

    public function deliveryTypes(): array
    {
        $types = [];
        foreach ($this->types->all() as $row) {
            $types[(string) $row['uuid']] = [
                'slug' => (string) $row['slug'],
                'public_delivery' => (bool) ($row['public_delivery'] ?? false),
            ];
        }
        return $types;
    }
}
