<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Routing;

use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\I18n\Contracts\LocaleManagerInterface;

use function config;

/**
 * Owns the global root URL namespace (root-mounted-types spec §3). A
 * root-mounted route slug lives directly under / — so it must never collide
 * with anything else the 1-segment resolver could parse: content-type slugs,
 * reserved prefixes/exact paths, active locale codes, reserved grammar
 * segments, other root-mounted routes, or root redirect sources (redirects
 * are keyed per type, so two root types could otherwise both own /old).
 *
 * All three write paths share this vocabulary: route assignment (409),
 * flipping mount_at_root ON (409 with the full conflict list), and content-
 * type creation (422). Resolve-time never shadows — the guard makes the
 * ambiguous states unrepresentable.
 */
final class RootMountGuard
{
    /** Reserved mid-grammar segments that stay unambiguous at root too. */
    private const RESERVED_SEGMENTS = ['page', 'terms'];

    /** First-segment prefixes owned by the app, beyond the render config. */
    private const APP_PREFIXES = ['_preview'];

    public function __construct(
        private readonly Connection $db,
        private readonly ContentTypeRepository $types,
        private readonly LocaleManagerInterface $locales,
        private readonly ApplicationContext $context,
    ) {
    }

    /**
     * Conflicts for claiming a root slug in a locale. $selfEntryUuid enables
     * the self-reclaim exception: an entry renaming back to its own previous
     * slug collides only with the redirect row assign() is about to delete.
     *
     * @return list<string> human-readable conflicts; [] = clear
     */
    public function conflictsForSlug(string $locale, string $slug, ?string $selfEntryUuid = null): array
    {
        $conflicts = $this->staticConflicts($slug);

        $route = $this->rootRouteBySlug($locale, $slug);
        if ($route !== null && ($selfEntryUuid === null || (string) $route['entry_uuid'] !== $selfEntryUuid)) {
            $conflicts[] = "'{$slug}' ({$locale}) is already a root-mounted page";
        }

        $redirect = $this->rootRedirectBySource($locale, $slug);
        if (
            $redirect !== null
            && ($selfEntryUuid === null || (string) ($redirect['target_entry_uuid'] ?? '') !== $selfEntryUuid)
        ) {
            $conflicts[] = "'{$slug}' ({$locale}) is a redirect source on another root-mounted type";
        }

        return $conflicts;
    }

    /**
     * Validate EVERY route + redirect source of a type before flipping
     * mount_at_root ON. The type's own rows are excluded from the namespace
     * they are being checked against (they are what is being admitted).
     *
     * @return list<string>
     */
    public function conflictsForType(string $typeUuid): array
    {
        $conflicts = [];

        $routes = $this->db->table('entry_routes')->select(['locale', 'slug', 'entry_uuid'])
            ->where('content_type_uuid', '=', $typeUuid)->get();
        foreach ($routes as $route) {
            foreach ($this->conflictsForSlug((string) $route['locale'], (string) $route['slug']) as $conflict) {
                $conflicts[] = $conflict;
            }
        }

        $redirects = $this->db->table('entry_redirects')->select(['locale', 'source_slug'])
            ->where('content_type_uuid', '=', $typeUuid)->get();
        foreach ($redirects as $redirect) {
            $locale = (string) $redirect['locale'];
            $source = (string) $redirect['source_slug'];
            foreach ($this->staticConflicts($source) as $conflict) {
                $conflicts[] = "redirect source {$conflict}";
            }
            if ($this->rootRouteBySlug($locale, $source) !== null) {
                $conflicts[] = "redirect source '{$source}' ({$locale}) is already a root-mounted page";
            }
            if ($this->rootRedirectBySource($locale, $source) !== null) {
                $conflicts[] = "redirect source '{$source}' ({$locale}) collides with another root redirect";
            }
        }

        return array_values(array_unique($conflicts));
    }

    /**
     * A NEW content-type slug vs existing root-mounted routes (spec §3 rule 3):
     * type precedence would silently shadow the live page.
     *
     * @return list<string>
     */
    public function typeSlugConflicts(string $typeSlug): array
    {
        $conflicts = [];
        $rows = $this->db->table('entry_routes')
            ->select(['entry_routes.locale'])
            ->join('content_types', 'content_types.uuid', '=', 'entry_routes.content_type_uuid')
            ->where('content_types.mount_at_root', '=', true)
            ->where('content_types.status', '!=', 'deleted')
            ->where('entry_routes.slug', '=', $typeSlug)
            ->get();
        foreach ($rows as $row) {
            $conflicts[] = "type slug '{$typeSlug}' would shadow the root-mounted page "
                . "'{$typeSlug}' ({$row['locale']})";
        }
        return $conflicts;
    }

    /**
     * Locale-independent rules: type slugs, reserved prefixes/exacts, active
     * locale codes, reserved grammar segments.
     *
     * @return list<string>
     */
    private function staticConflicts(string $slug): array
    {
        $conflicts = [];

        if ($this->types->findBySlug($slug) !== null) {
            $conflicts[] = "'{$slug}' is a content-type slug (type paths take precedence)";
        }

        $reserved = array_merge(
            array_map(strval(...), (array) config($this->context, 'render.reserved_prefixes', [])),
            array_map(strval(...), (array) config($this->context, 'render.reserved_exact', [])),
            self::APP_PREFIXES,
            self::RESERVED_SEGMENTS,
        );
        if (in_array($slug, $reserved, true)) {
            $conflicts[] = "'{$slug}' is a reserved path segment";
        }

        foreach ($this->locales->enabled() as $row) {
            if ((string) ($row['code'] ?? '') === $slug) {
                $conflicts[] = "'{$slug}' is an active locale code";
                break;
            }
        }

        return $conflicts;
    }

    /** @return array<string,mixed>|null */
    private function rootRouteBySlug(string $locale, string $slug): ?array
    {
        return $this->db->table('entry_routes')
            ->select(['entry_routes.entry_uuid'])
            ->join('content_types', 'content_types.uuid', '=', 'entry_routes.content_type_uuid')
            ->where('content_types.mount_at_root', '=', true)
            ->where('content_types.status', '!=', 'deleted')
            ->where('entry_routes.locale', '=', $locale)
            ->where('entry_routes.slug', '=', $slug)
            ->first() ?: null;
    }

    /** @return array<string,mixed>|null */
    private function rootRedirectBySource(string $locale, string $slug): ?array
    {
        return $this->db->table('entry_redirects')
            ->select(['entry_redirects.target_entry_uuid'])
            ->join('content_types', 'content_types.uuid', '=', 'entry_redirects.content_type_uuid')
            ->where('content_types.mount_at_root', '=', true)
            ->where('content_types.status', '!=', 'deleted')
            ->where('entry_redirects.locale', '=', $locale)
            ->where('entry_redirects.source_slug', '=', $slug)
            ->first() ?: null;
    }
}
