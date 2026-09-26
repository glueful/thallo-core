<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Forms;

/**
 * Source-scoped identity for form_key (form-block spec §5), first match wins: a layout's form is one
 * form across every page of its type (`layout:{surface}:{target}`), a header or footer form one form
 * across the site (`region:{slug}`), a body form its page's (`entry:{uuid}`), then the route, then a
 * deterministic tail. The region used to come after the entry, so chrome forms took each page's
 * identity; tokens sealed that way keep their key (the descriptor carries it).
 */
final class FormSourceIdentity
{
    /** @param array<string,mixed>|null $entry */
    public static function resolve(
        ?array $entry,
        ?string $regionSlug,
        ?string $currentPath,
        ?string $layoutSource = null,
    ): string {
        if (is_string($layoutSource) && $layoutSource !== '') {
            return 'layout:' . $layoutSource;
        }
        if (is_string($regionSlug) && $regionSlug !== '') {
            return 'region:' . $regionSlug;
        }
        if (is_array($entry) && is_string($entry['uuid'] ?? null) && $entry['uuid'] !== '') {
            return 'entry:' . $entry['uuid'];
        }
        if (is_string($currentPath) && $currentPath !== '') {
            return 'route:' . $currentPath;
        }
        return 'theme:path:/'; // deterministic final fallback (spec §5)
    }
}
