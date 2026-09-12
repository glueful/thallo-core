<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Delivery;

use Thallo\Core\Content\Seo\CanonicalPathBuilder;
use Thallo\Core\Settings\GeneralSettings;
use Glueful\Database\Connection;
use Thallo\Contracts\Delivery\PublishedPageDirectory;

/**
 * The app's {@see PublishedPageDirectory}: published, publicly-delivered pages as convenience
 * redirect targets. Mirrors the sitemap's publication spine (active entries, `public_delivery`
 * types, pinned publication joined to its route) but keeps only the DEFAULT locale so a page
 * appears once as its bare canonical path, and turns each row into a path via
 * {@see CanonicalPathBuilder}. Capped so the suggestion list never grows unbounded.
 */
final class PublishedPageDirectoryBridge implements PublishedPageDirectory
{
    /** Bound so a large catalog never produces a runaway suggestion list. */
    private const CAP = 200;

    public function __construct(
        private readonly Connection $db,
        private readonly CanonicalPathBuilder $paths,
        private readonly GeneralSettings $settings,
    ) {
    }

    /** @return list<array{label: string, path: string}> */
    public function publicPages(): array
    {
        $locale = $this->settings->defaultLocale();

        $rows = $this->db->table('entry_publications as p')
            ->join('entries as e', 'e.uuid', '=', 'p.entry_uuid')
            ->join('entry_routes as r', 'r.entry_uuid', '=', 'p.entry_uuid')
            ->join('content_types as ct', 'ct.uuid', '=', 'e.content_type_uuid')
            ->select(['ct.slug as type_slug', 'ct.mount_at_root', 'r.slug'])
            ->where('e.status', '=', 'active')            // never archived/deleted
            ->where('ct.public_delivery', '=', true)      // public pages only
            ->where('p.locale', '=', $locale)             // default locale → one canonical path per page
            ->whereRaw('r.content_type_uuid = e.content_type_uuid')
            ->whereRaw('r.locale = p.locale')
            ->orderByRaw('p.published_at DESC, p.entry_uuid ASC')
            ->limit(self::CAP)
            ->get();

        $seen = [];
        $pages = [];
        foreach ($rows as $row) {
            // CanonicalPathBuilder returns an ABSOLUTE canonical URL when PUBLIC_URL_BASE is set; a
            // redirect target must be site-relative, so keep only the path component.
            $url = $this->paths->pathFor(
                (string) $row['type_slug'],
                $this->truthy($row['mount_at_root'] ?? false),
                $locale,
                (string) $row['slug'],
            );
            $path = parse_url($url, PHP_URL_PATH);
            if (!is_string($path) || $path === '' || isset($seen[$path])) {
                continue;
            }
            $seen[$path] = true;
            $pages[] = ['label' => $path, 'path' => $path];
        }

        return $pages;
    }

    /** Postgres booleans arrive as native bool or 't'/'f'; normalize either shape. */
    private function truthy(mixed $value): bool
    {
        return $value === true || $value === 't' || $value === 1 || $value === '1';
    }
}
