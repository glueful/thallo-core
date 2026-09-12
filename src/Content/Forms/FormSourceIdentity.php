<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Forms;

/** Source-scoped identity for form_key (form-block spec §5): first match wins, deterministic tail. */
final class FormSourceIdentity
{
    /** @param array<string,mixed>|null $entry */
    public static function resolve(?array $entry, ?string $regionSlug, ?string $currentPath): string
    {
        if (is_array($entry) && is_string($entry['uuid'] ?? null) && $entry['uuid'] !== '') {
            return 'entry:' . $entry['uuid'];
        }
        if (is_string($regionSlug) && $regionSlug !== '') {
            return 'region:' . $regionSlug;
        }
        if (is_string($currentPath) && $currentPath !== '') {
            return 'route:' . $currentPath;
        }
        return 'theme:path:/'; // deterministic final fallback (spec §5)
    }
}
