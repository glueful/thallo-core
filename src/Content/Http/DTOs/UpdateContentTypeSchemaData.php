<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs;

use Glueful\Validation\Attributes\ArrayOf;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Request body for `PATCH /v1/admin/content-types/{slug}/schema`
 * ({@see \Thallo\Core\Content\Http\Controllers\ContentTypeController::updateSchema()}).
 *
 * Hydrated by the router (v2): each field definition's structure is validated here;
 * semantic schema validation stays in the repository (`SchemaParseException` → 422).
 */
final class UpdateContentTypeSchemaData implements RequestData
{
    /** @param list<FieldDefinitionData> $schema */
    public function __construct(
        #[ArrayOf(FieldDefinitionData::class)]
        #[Rule('required|array')]
        public readonly array $schema,
    ) {
    }
}
