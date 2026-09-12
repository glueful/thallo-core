<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Localization;

use Thallo\Core\Content\Schema\ContentTypeSchema;

/**
 * Computes the initial field map for a new locale variant from a source locale.
 */
final class LocaleFieldSeeder
{
    /**
     * @param array<string,mixed> $sourceFields
     * @return array<string,mixed>
     */
    public function seed(array $sourceFields, ContentTypeSchema $schema): array
    {
        $seed = [];
        foreach ($schema->fields() as $field) {
            if ($field->localized) {
                continue;
            }
            if (array_key_exists($field->name, $sourceFields)) {
                $seed[$field->name] = $sourceFields[$field->name];
            }
        }

        return $seed;
    }
}
