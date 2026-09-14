<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Blocks\Sources;

use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Schema\ContentTypeSchema;

/** The content types with at least one blocks field, with their parsed schemas (shared by the entry sources). */
final class BlockContentTypes
{
    public function __construct(private readonly ContentTypeRepository $contentTypes)
    {
    }

    /** @return list<array{uuid: string, slug: string, schema: ContentTypeSchema, schema_version: int}> */
    public function all(): array
    {
        $out = [];
        foreach ($this->contentTypes->all() as $ct) {
            $schema = ContentTypeSchema::fromArray((array) $ct['schema']);
            foreach ($schema->fields() as $field) {
                if ($field->type === 'blocks') {
                    $out[] = [
                        'uuid' => (string) $ct['uuid'],
                        'slug' => (string) $ct['slug'],
                        'schema' => $schema,
                        'schema_version' => (int) ($ct['schema_version'] ?? 1),
                    ];
                    break;
                }
            }
        }
        return $out;
    }

    /** @return array<string,mixed> */
    public static function decode(mixed $fields): array
    {
        if (is_string($fields)) {
            $decoded = json_decode($fields, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($fields) ? $fields : [];
    }
}
