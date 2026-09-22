<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Navigation;

use Thallo\Contracts\Navigation\MenuUsageReader;
use Thallo\Contracts\Navigation\MenuUse;
use Thallo\Core\Content\Blocks\Sources\BlockDocumentSources;
use Thallo\Core\Content\Blocks\Sources\DocumentRef;
use Thallo\Core\Content\Blocks\Sources\EntryDraftsSource;
use Thallo\Core\Content\Blocks\Sources\PublishedEntriesSource;
use Thallo\Core\Content\Blocks\Sources\RegionsSource;

/**
 * Finds a menu's Navigation blocks across the regions, every entry draft and every published
 * version, at any nesting depth. Read on demand (a delete confirmation), so it walks rather than
 * keeping a projection.
 */
final class EngineMenuUsageReader implements MenuUsageReader
{
    public function __construct(private readonly BlockDocumentSources $sources)
    {
    }

    public function usage(string $menuSlug): array
    {
        $found = [];
        $this->sources
            ->only(RegionsSource::ID, EntryDraftsSource::ID, PublishedEntriesSource::ID)
            ->each(function ($source, DocumentRef $ref) use ($menuSlug, &$found): void {
                if (!self::names($ref->fields, $menuSlug)) {
                    return;
                }
                if ($ref->sourceType === RegionsSource::ID) {
                    $found['region:' . $ref->sourceId] = new MenuUse(
                        MenuUse::REGION,
                        $ref->sourceId,
                        ucfirst($ref->sourceId),
                    );
                    return;
                }
                $title = $ref->fields['title'] ?? null;
                $found['entry:' . $ref->sourceId] ??= new MenuUse(
                    MenuUse::ENTRY,
                    $ref->sourceId,
                    is_string($title) && trim($title) !== '' ? trim($title) : $ref->sourceId,
                    isset($ref->meta['content_type']) ? (string) $ref->meta['content_type'] : null,
                );
            });
        $regions = array_filter($found, static fn(MenuUse $use): bool => $use->kind === MenuUse::REGION);
        $entries = array_filter($found, static fn(MenuUse $use): bool => $use->kind === MenuUse::ENTRY);

        return [...array_values($regions), ...array_values($entries)];
    }

    /** Whether any block under `$value` is a Navigation block showing the menu. */
    private static function names(mixed $value, string $menuSlug): bool
    {
        if (!is_array($value)) {
            return false;
        }
        if (($value['type'] ?? null) === 'navigation' && ($value['data']['menu'] ?? null) === $menuSlug) {
            return true;
        }
        foreach ($value as $child) {
            if (is_array($child) && self::names($child, $menuSlug)) {
                return true;
            }
        }
        return false;
    }
}
