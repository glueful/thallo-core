<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Delivery;

use Thallo\Core\Content\Preview\PreviewNotFoundException;
use Thallo\Core\Content\Preview\PreviewReader;
use Thallo\Core\Content\Preview\PreviewTokenException;
use Thallo\Core\Content\Preview\PreviewWorkingCopyStore;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\EntryRepository;
use Thallo\Core\Content\Repositories\PublishedReferenceRepository;
use Thallo\Core\Content\Schema\ContentTypeSchema;
use Thallo\Core\Content\Seo\CanonicalPathBuilder;
use Thallo\Core\Content\Seo\RouteResolver;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\I18n\Contracts\LocaleManagerInterface;
use Thallo\Contracts\Delivery\PreviewSession;
use Thallo\Contracts\Delivery\PublicRouteResolver;
use Thallo\Contracts\Delivery\ReferenceTargetResolver;
use Glueful\Support\FieldSelection\FieldSelector;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

use function config;

/**
 * Path → published content for the render pack, wrapping the existing RouteResolver.
 * Owns raw-path parsing (the inverse of PathRenderer's /{locale}/{type}/{slug} template)
 * and NORMALIZATION-FIRST canonical redirects (render spec §3): /blog//hello 301s before
 * any parsing or lookup, so content resolution only ever sees canonical paths. Render is
 * an ANONYMOUS surface: non-public-delivery types resolve not_found even with a live
 * route ({@see DeliveryVisibility} with null scopes — the same rule anonymous delivery
 * enforces).
 */
final class EnginePublicRouteResolver implements PublicRouteResolver
{
    public function __construct(
        private readonly Connection $db,
        private readonly ContentTypeRepository $types,
        private readonly RouteResolver $routes,
        private readonly LocaleManagerInterface $locales,
        private readonly DeliveryItemShaper $shaper,
        private readonly ApplicationContext $context,
        private readonly DeliveryRepository $delivery,
        private readonly PublishedReferenceRepository $projection,
        private readonly ReferenceTargetResolver $terms,
        private readonly CanonicalPathBuilder $canonical,
        private readonly PreviewReader $preview,
        private readonly EntryRepository $entries,
        private readonly LoggerInterface $logger,
        private readonly ListingItemShaper $listShaper,
        /** Loop C working-copy stash; null = no ephemeral overlay (minimal wiring). */
        private readonly ?PreviewWorkingCopyStore $workingCopies = null,
        /** Listing allowlist DB setting; null = config-only (minimal wiring). */
        private readonly ?\Thallo\Core\Settings\GeneralSettings $settings = null,
    ) {
    }

    public function resolvePath(string $path, ?PreviewSession $previewSession = null): array
    {
        $raw = $path === '' ? '/' : $path;
        $normalized = $this->normalize($raw);
        if ($normalized !== $raw) {
            return $this->redirect($normalized, 301);
        }

        $segments = array_values(array_filter(
            explode('/', trim($normalized, '/')),
            static fn(string $s): bool => $s !== '',
        ));
        $decoded = array_map(rawurldecode(...), $segments);

        // Locale-wins rule at EVERY length (listing spec §1): an ACTIVE-locale first
        // segment is always the locale, never a type slug.
        $locale = $this->locales->default();
        $prefix = '';
        if (count($decoded) >= 2 && $this->isActiveLocale($decoded[0])) {
            $locale = array_shift($decoded);
            $prefix = '/' . rawurlencode($locale);
            array_shift($segments);
        }

        switch (count($decoded)) {
            case 1: // /{type} → listing page 1; else root-mounted entry/redirect
                // Type paths take precedence (root-mounted-types spec §2):
                // types are few and explicit, and the write-time RootMountGuard
                // makes the shadowing state unrepresentable anyway.
                if ($this->types->findBySlug($decoded[0]) !== null) {
                    return $this->resolveListing($decoded[0], $locale, 1);
                }
                return $this->resolveRootEntry($decoded[0], $locale, $previewSession);
            case 2: // /{type}/{slug} → entry (existing behavior)
                [$typeSlug, $slug] = $decoded;
                break;
            case 3:
                // `page` is RESERVED as a field segment: pagination parses first.
                if ($decoded[1] === 'page') {
                    return $this->pagedOrCanonical(
                        $decoded[2],
                        $prefix . '/' . $segments[0],
                        fn(int $n): array => $this->resolveListing($decoded[0], $locale, $n),
                    );
                }
                // `terms` is RESERVED like `page` (term-index spec §1): parses before
                // archive lookup, so an archive FIELD named `terms` is shadowed
                // (documented cost); 2-segment entry paths are untouched.
                if ($decoded[1] === 'terms') {
                    return $this->resolveTerms($decoded[0], $decoded[2], $locale);
                }
                // /{type}/{field}/{term} → archive page 1
                return $this->resolveArchive($decoded[0], $decoded[1], $decoded[2], $locale, 1);
            case 5:
                if ($decoded[3] === 'page') {
                    // The `terms` reservation holds at 5 segments too: term indexes
                    // have no pagination, and an archive FIELD named `terms` must not
                    // leak its paged form. page/1 keeps the shared canonical 301
                    // (redirects to the 3-segment path, which parses as the index).
                    if ($decoded[1] === 'terms') {
                        if (ctype_digit($decoded[4]) && (int) $decoded[4] === 1) {
                            return $this->redirect(
                                $prefix . '/' . implode('/', array_slice($segments, 0, 3)),
                                301,
                            );
                        }
                        return $this->notFound();
                    }
                    return $this->pagedOrCanonical(
                        $decoded[4],
                        $prefix . '/' . implode('/', array_slice($segments, 0, 3)),
                        fn(int $n): array => $this->resolveArchive(
                            $decoded[0],
                            $decoded[1],
                            $decoded[2],
                            $locale,
                            $n,
                        ),
                    );
                }
                return $this->notFound();
            default:
                return $this->notFound();
        }

        $typeRow = $this->types->findBySlug($typeSlug);
        if ($typeRow === null || !$this->isPubliclyDeliverable($typeRow)) {
            return $this->notFound();
        }

        return $this->resolveTypedEntry($typeRow, $slug, $locale, $previewSession, viaPrefixedPath: true);
    }

    /**
     * The shared entry tail (root-mounted-types spec §2): per-type route/
     * redirect resolution, preview-session + working-copy overlays, shaping.
     * Both grammars end here — /{type}/{slug} and the root /{slug} — so the
     * overlays and presentation extraction apply at root by construction.
     *
     * @param array<string,mixed> $typeRow
     * @return array<string,mixed>
     */
    private function resolveTypedEntry(
        array $typeRow,
        string $slug,
        string $locale,
        ?PreviewSession $previewSession,
        bool $viaPrefixedPath,
    ): array {
        $typeUuid = (string) $typeRow['uuid'];
        $typeSlug = (string) $typeRow['slug'];

        $result = $this->routes->resolve($typeUuid, $typeSlug, $this->localeChain($locale), $slug);
        if ($result === null) {
            return $this->notFound();
        }
        if ($result->isGone()) {
            return $this->gone($locale);
        }
        if ($result->isRedirect()) {
            $descriptor = $result->redirect();
            return $this->redirect((string) $descriptor['to'], (int) $descriptor['status']);
        }

        $row = $result->content();

        // Canonical collapse (spec §2): a root-mounted type reached via its
        // PREFIXED path 301s to the root canonical — CONTENT ONLY (redirect
        // rows already followed their own descriptor above; one hop, never
        // /pages/old -> /old -> /new). Before the overlays, so preview
        // sessions canonicalize too and overlay at the root path.
        if ($viaPrefixedPath && (bool) ($typeRow['mount_at_root'] ?? false)) {
            return $this->redirect(
                $this->canonical->pathFor($typeSlug, true, (string) $row['locale'], $slug),
                301,
            );
        }

        // Single-draft overlay (preview-sessions spec §3): the session's OWN entry
        // shows its draft at its canonical URL; everything else stays published.
        if (
            $previewSession !== null
            && $previewSession->entry === (string) $row['entry_uuid']
            && $previewSession->locale === (string) $row['locale']
        ) {
            try {
                return $this->previewContent(
                    $this->overlayWorkingCopy($this->preview->readVerified($previewSession))
                );
            } catch (PreviewNotFoundException) {
                // Draft vanished mid-session: fall through to the published render.
            }
        }

        $expanded = new ExpandedTargets();
        return [
            'kind' => 'content',
            'locale' => (string) $row['locale'],
            'type' => $typeSlug,
            'content' => $this->shaper->shapePublic($row, $typeUuid, $typeSlug, $expanded),
            'redirect' => null,
            'listing' => null, 'term' => null, 'term_type' => null, 'field' => null,
            'preview' => false,
            'presentation' => $this->presentationOf($row),
            'cache_tags' => $this->expansionTags($expanded),
        ];
    }

    /**
     * Root grammar, 1 segment (root-mounted-types spec §2): a root-mounted
     * entry lookup through the locale chain, then the root redirect fallback,
     * then 404. Only root-mounted, publicly deliverable, active types
     * participate; the RootMountGuard keeps the namespace collision-free so
     * the first match is the only possible match.
     *
     * @return array<string,mixed>
     */
    private function resolveRootEntry(string $slug, string $locale, ?PreviewSession $previewSession): array
    {
        foreach ($this->localeChain($locale) as $chainLocale) {
            $route = $this->db->table('entry_routes')
                ->select(['entry_routes.content_type_uuid'])
                ->join('content_types', 'content_types.uuid', '=', 'entry_routes.content_type_uuid')
                ->where('content_types.mount_at_root', '=', true)
                ->where('content_types.status', '!=', 'deleted')
                ->where('entry_routes.locale', '=', $chainLocale)
                ->where('entry_routes.slug', '=', $slug)
                ->first();
            if ($route === null) {
                continue;
            }
            $typeRow = $this->types->findByUuid((string) $route['content_type_uuid']);
            if ($typeRow === null || !$this->isPubliclyDeliverable($typeRow)) {
                return $this->notFound();
            }
            return $this->resolveTypedEntry($typeRow, $slug, $locale, $previewSession, viaPrefixedPath: false);
        }

        $result = $this->routes->resolveRootRedirect($locale, $slug);
        if ($result !== null) {
            if ($result->isGone()) {
                return $this->gone($locale);
            }
            if ($result->isRedirect()) {
                $descriptor = $result->redirect();
                return $this->redirect((string) $descriptor['to'], (int) $descriptor['status']);
            }
        }

        return $this->notFound();
    }

    /** @return array<string,mixed> the shared gone shape */
    private function gone(string $locale): array
    {
        return [
            'kind' => 'gone', 'locale' => $locale, 'type' => null, 'content' => null, 'redirect' => null,
            'listing' => null, 'term' => null, 'term_type' => null, 'field' => null,
            'preview' => false,
        ];
    }

    public function resolveEntry(
        string $entryUuid,
        ?string $locale = null,
        ?PreviewSession $previewSession = null,
    ): array {
        $locale = $locale !== null && $locale !== '' ? $locale : $this->locales->default();

        $entry = $this->db->table('entries')->select(['content_type_uuid', 'status'])
            ->where('uuid', '=', $entryUuid)->first();
        if ($entry === null || ($entry['status'] ?? null) === 'deleted') {
            return $this->notFound();
        }
        $typeRow = $this->types->findByUuid((string) $entry['content_type_uuid']);
        if ($typeRow === null || !$this->isPubliclyDeliverable($typeRow)) {
            return $this->notFound();
        }
        $typeUuid = (string) $typeRow['uuid'];
        $typeSlug = (string) $typeRow['slug'];

        // ROUTELESS is not_found here: a published entry with no route cannot be a
        // homepage target (the EntryTargetResolver rule — no consumer renders unroutable
        // content). Redirect/gone from a uuid resolution are also not_found: a homepage
        // must point at live content directly.
        $route = $this->db->table('entry_routes')->select(['slug'])
            ->where('entry_uuid', '=', $entryUuid)->where('locale', '=', $locale)->first();
        if ($route === null) {
            return $this->notFound();
        }

        $result = $this->routes->resolve($typeUuid, $typeSlug, $this->localeChain($locale), $entryUuid);
        if ($result === null || !$result->isContent()) {
            return $this->notFound();
        }

        $row = $result->content();

        // Single-draft overlay at '/' (working-copy-overlay spec §2): the
        // session's OWN entry shows its draft — or the canvas's stashed
        // working copy — as the homepage; any other session leaves the
        // homepage fully published.
        if (
            $previewSession !== null
            && $previewSession->entry === (string) $row['entry_uuid']
            && $previewSession->locale === (string) $row['locale']
        ) {
            try {
                return $this->previewContent(
                    $this->overlayWorkingCopy($this->preview->readVerified($previewSession))
                );
            } catch (PreviewNotFoundException) {
                // Draft vanished mid-session: fall through to the published render.
            }
        }

        $expanded = new ExpandedTargets();
        return [
            'kind' => 'content',
            'locale' => (string) $row['locale'],
            'type' => $typeSlug,
            'content' => $this->shaper->shapePublic($row, $typeUuid, $typeSlug, $expanded),
            'redirect' => null,
            'listing' => null, 'term' => null, 'term_type' => null, 'field' => null,
            'preview' => false,
            'presentation' => $this->presentationOf($row),
            'cache_tags' => $this->expansionTags($expanded),
        ];
    }

    /**
     * Signed-token preview (preview spec §2): kind 'content' + preview: true — a
     * content render, not a new kind. Fields come from the fail-closed PreviewReader
     * (draft or pinned version, schema-projected); shaping is the LIST shape — no seo
     * (a draft may be routeless; the page is noindex). Token-is-authorization:
     * public_delivery gating deliberately does NOT apply (JSON-door parity). Every
     * failure is not_found; the reason is logged for editor debuggability.
     */
    public function resolvePreview(string $token): array
    {
        try {
            $read = $this->preview->read($token);
        } catch (PreviewTokenException | PreviewNotFoundException $e) {
            $this->logger->info('thallo-render: preview token rejected', [
                'reason' => get_class($e),
                'error' => $e->getMessage(),
            ]);
            return $this->notFound();
        }

        return $this->previewContent($this->overlayWorkingCopy($read));
    }

    /**
     * Loop C overlay, session-wide (working-copy-overlay spec): an existing
     * working copy wins over the draft for DRAFT-MODE reads only — a
     * version-pinned token or session renders its immutable version, never
     * the working copy (hard pin). Keyed off the read result's OWN
     * entry/locale (spec pin) — the read is the thing being shaped, so it
     * decides which stash can apply; never route params or separately-copied
     * session payload.
     *
     * @param array{entry_uuid:string,locale:string,version_uuid:?string,
     *               version:?int,schema_version:int,fields:array<string,mixed>} $read
     * @return array{entry_uuid:string,locale:string,version_uuid:?string,
     *               version:?int,schema_version:int,fields:array<string,mixed>}
     */
    /**
     * The RAW per-page presentation override (modern-default-theme spec §5a),
     * extracted BEFORE shaping: the delivery shaper's schema allowlist strips
     * _presentation from shaped content, so this is the only door it reaches
     * the renderer through. Null = no override (theme defaults apply).
     *
     * @param array<string,mixed> $rowOrRead a spine row or reader result
     * @return array<string,mixed>|null
     */
    private function presentationOf(array $rowOrRead): ?array
    {
        $fields = $rowOrRead['fields'] ?? null;
        if (is_string($fields)) {
            $fields = json_decode($fields, true);
        }
        if (!is_array($fields)) {
            return null;
        }
        $presentation = $fields['_presentation'] ?? null;
        return is_array($presentation) && $presentation !== [] ? $presentation : null;
    }

    private function overlayWorkingCopy(array $read): array
    {
        if ($read['version_uuid'] !== null || $this->workingCopies === null) {
            return $read;
        }
        $working = $this->workingCopies->get($read['entry_uuid'], $read['locale']);
        if ($working !== null) {
            $read['fields'] = $working;
        }
        return $read;
    }

    /**
     * Draft/pinned-version content from a READER RESULT — the shared shaping between
     * resolvePreview() (token path) and the session overlay (verified-VO path).
     *
     * @param array{entry_uuid:string,locale:string,version_uuid:?string,
     *               version:?int,schema_version:int,fields:array<string,mixed>} $read
     * @return array<string,mixed>
     */
    private function previewContent(array $read): array
    {
        $entry = $this->entries->findEntry($read['entry_uuid']);
        if ($entry === null || ($entry['status'] ?? null) === 'deleted') {
            return $this->notFound();
        }
        $typeRow = $this->types->findByUuid((string) $entry['content_type_uuid']);
        if ($typeRow === null) {
            return $this->notFound();
        }
        $typeUuid = (string) $typeRow['uuid'];
        $typeSlug = (string) $typeRow['slug'];
        $schema = ContentTypeSchema::fromArray((array) ($typeRow['schema'] ?? []));

        // Synthesize a spine-shaped row from the reader's output; references expand
        // against the PUBLISHED spine (referenced entries appear as the public sees
        // them). schema_version is pinned to the CURRENT type version: PreviewReader
        // already projected the fields forward but reports the ORIGINAL version, and
        // letting shape() see the old number would re-run the migration chain over
        // already-projected fields — unsafe when a rename chain re-uses a name.
        $row = [
            'entry_uuid' => $read['entry_uuid'],
            'locale' => $read['locale'],
            'version' => $read['version'],
            'version_uuid' => $read['version_uuid'],
            'schema_version' => (int) ($typeRow['schema_version'] ?? $read['schema_version']),
            'fields' => $read['fields'],
            'published_at' => null,
        ];
        $selector = FieldSelector::fromRequest(Request::create('/'));
        $shaped = $this->shaper->shape([$row], $schema, $selector, $read['locale'], $typeUuid, null);
        $content = $this->shaper->item($shaped[0]);

        return [
            'kind' => 'content', 'locale' => $read['locale'], 'type' => $typeSlug,
            'content' => $content, 'redirect' => null,
            'listing' => null, 'term' => null, 'term_type' => null, 'field' => null,
            'preview' => true,
            'presentation' => $this->presentationOf($read),
        ];
    }

    /**
     * Shared page-number handling (listing spec §1 pins): non-numeric or < 1 →
     * not_found; exactly 1 → 301 to the canonical bare path; otherwise resolve page n.
     *
     * @param callable(int): array<string,mixed> $resolve
     * @return array<string,mixed>
     */
    private function pagedOrCanonical(string $rawPage, string $basePath, callable $resolve): array
    {
        if (!ctype_digit($rawPage)) {
            return $this->notFound();
        }
        $n = (int) $rawPage;
        if ($n === 1) {
            return $this->redirect($basePath, 301);
        }
        if ($n < 1) {
            return $this->notFound();
        }
        return $resolve($n);
    }

    /**
     * /{type}[/page/n] → listing. Dormant unless the type is allowlisted in
     * render.listing_types (a render-owned key read softly — this grammar exists
     * only for rendered delivery; pack absent / key empty ⇒ not_found).
     *
     * @return array<string,mixed>
     */
    private function resolveListing(string $typeSlug, string $locale, int $page): array
    {
        if (!in_array($typeSlug, $this->listingTypes(), true)) {
            return $this->notFound();
        }
        $typeRow = $this->types->findBySlug($typeSlug);
        if ($typeRow === null || !$this->isPubliclyDeliverable($typeRow)) {
            return $this->notFound();
        }
        $typeUuid = (string) $typeRow['uuid'];

        $result = $this->paginate($typeUuid, $locale, $page, null);
        if ($result === null) {
            return $this->notFound();
        }
        [$listing, $rows] = $result;
        $expanded = new ExpandedTargets();
        $listing['items'] = $this->listItems($rows, $typeRow, $locale, $expanded);

        return [
            'kind' => 'listing', 'locale' => $locale, 'type' => $typeSlug,
            'content' => null, 'redirect' => null,
            'listing' => $listing, 'term' => null, 'term_type' => null, 'field' => null,
            'preview' => false,
            'cache_tags' => $this->expansionTags($expanded),
        ];
    }

    /**
     * /{type}/{field}/{term}[/page/n] → term archive. Same allowlist + anonymous gates
     * as listings; membership is the published-reference projection (the API archive
     * endpoint's single source — the surfaces cannot diverge); the term resolves
     * uuid-first then referenceSlugField, and ambiguity is not_found on this anonymous
     * surface (the API 422s — deliberate, spec §2).
     *
     * @return array<string,mixed>
     */
    private function resolveArchive(
        string $typeSlug,
        string $field,
        string $term,
        string $locale,
        int $page,
    ): array {
        if (!in_array($typeSlug, $this->listingTypes(), true)) {
            return $this->notFound();
        }
        $typeRow = $this->types->findBySlug($typeSlug);
        if ($typeRow === null || !$this->isPubliclyDeliverable($typeRow)) {
            return $this->notFound();
        }
        $typeUuid = (string) $typeRow['uuid'];
        $schema = ContentTypeSchema::fromArray((array) ($typeRow['schema'] ?? []));

        $fieldDef = $schema->field($field);
        if ($fieldDef === null || $fieldDef->type !== 'reference' || !$fieldDef->filterable) {
            return $this->notFound();
        }
        $targetRow = $this->types->findBySlug((string) ($fieldDef->referenceType ?? ''));
        if ($targetRow === null || !$this->isPubliclyDeliverable($targetRow)) {
            return $this->notFound(); // fail closed — the term body is page content
        }
        $targetUuid = (string) $targetRow['uuid'];
        $targetSlug = (string) $targetRow['slug'];

        try {
            $targets = $this->terms->resolve($fieldDef, $locale, [$term]);
        } catch (InvalidFilterException) {
            return $this->notFound(); // ambiguous slug: anonymous surface has no 422
        }
        $termUuid = $targets[0] ?? null;
        if ($termUuid === null) {
            return $this->notFound();
        }
        $termRow = $this->delivery->findPublishedByUuid($targetUuid, $locale, $termUuid);
        if ($termRow === null) {
            return $this->notFound();
        }

        $result = $this->paginate(
            $typeUuid,
            $locale,
            $page,
            $this->projection->membershipPredicate($typeUuid, $field, $termUuid),
        );
        if ($result === null) {
            return $this->notFound();
        }
        [$listing, $rows] = $result;
        // One collector spans the term AND the member items (spec §4).
        $expanded = new ExpandedTargets();
        $listing['items'] = $this->listItems($rows, $typeRow, $locale, $expanded);

        return [
            'kind' => 'archive', 'locale' => $locale, 'type' => $typeSlug,
            'content' => null, 'redirect' => null,
            'listing' => $listing,
            'term' => $this->shaper->shapePublic($termRow, $targetUuid, $targetSlug, $expanded),
            'term_type' => $targetSlug,
            'field' => $field,
            'preview' => false,
            'cache_tags' => $this->expansionTags($expanded),
        ];
    }

    /**
     * /{type}/terms/{field} → term index (term-index spec §2): a THIN kind — the
     * render controller fetches counts itself via FacetCountsReader and dispatches on
     * its invariant (empty cache_tags = gate failure). Only the listing allowlist is
     * resolver-side (grammar gate); field/visibility gates live in the reader.
     *
     * @return array<string,mixed>
     */
    private function resolveTerms(string $typeSlug, string $field, string $locale): array
    {
        if (!in_array($typeSlug, $this->listingTypes(), true)) {
            return $this->notFound();
        }
        return [
            'kind' => 'terms', 'locale' => $locale, 'type' => $typeSlug,
            'content' => null, 'redirect' => null,
            'listing' => null, 'term' => null, 'term_type' => null, 'field' => $field,
            'preview' => false,
        ];
    }

    /**
     * Paginate the published spine; null = page beyond range. total_pages is pinned to
     * max(1, ceil(total / per_page)) so page 1 of an EMPTY listing is valid (spec §1 —
     * a naive ceil(0/n) = 0 would make page 1 "beyond range").
     *
     * @param array{sql: string, bindings: list<mixed>}|null $filter
     * @return array{0: array{page: int, per_page: int, total: int, total_pages: int},
     *   1: list<array<string,mixed>>}|null
     */
    private function paginate(string $typeUuid, string $locale, int $page, ?array $filter): ?array
    {
        $perPage = max(1, (int) config($this->context, 'render.listing_per_page', 10));
        $result = $this->delivery->paginatePublished($typeUuid, $locale, $page, $perPage, $filter, null);
        $totalPages = max(1, (int) ceil($result['total'] / $perPage));
        if ($page > $totalPages) {
            return null;
        }
        return [
            [
                'page' => $page,
                'per_page' => $perPage,
                'total' => (int) $result['total'],
                'total_pages' => $totalPages,
            ],
            $result['data'],
        ];
    }

    /**
     * LIST-shaped items + a ready per-item `href` (listing spec §2 pin): the delivery
     * list shape (no per-item seo — shapePublic is a SHOW shape), hrefs batch-rendered
     * from ONE entry_routes query, default locale collapsed exactly like canonicals.
     * Routeless entries carry href: null.
     *
     * @param list<array<string,mixed>> $rows
     * @param array<string,mixed> $typeRow
     * @return list<array<string,mixed>>
     */
    private function listItems(array $rows, array $typeRow, string $locale, ?ExpandedTargets $expanded = null): array
    {
        return $this->listShaper->shape($rows, $typeRow, $locale, $expanded ?? new ExpandedTargets());
    }

    /**
     * Full surrogate tag strings for the collected expansion targets (spec §4) —
     * carried on the resolver result as `cache_tags` for the render controller to
     * merge; NEVER placed inside the content payload.
     *
     * @return list<string>
     */
    private function expansionTags(ExpandedTargets $expanded): array
    {
        return array_map(
            static fn(string $uuid): string => 'thallo:entry:' . $uuid,
            $expanded->entryUuids(),
        );
    }

    /**
     * The listing allowlist — a General SETTING since the taxonomy defaults
     * work: the DB row wins ('' = explicitly none) with the deploy config as
     * the pre-first-save fallback; GeneralSettings owns that chain.
     *
     * @return list<string>
     */
    private function listingTypes(): array
    {
        if ($this->settings !== null) {
            return $this->settings->listingTypes();
        }
        return array_values(array_filter(array_map(
            strval(...),
            (array) config($this->context, 'render.listing_types', []),
        )));
    }

    private function normalize(string $path): string
    {
        $collapsed = preg_replace('#/{2,}#', '/', '/' . trim($path, " \t")) ?? $path;
        $collapsed = str_starts_with($collapsed, '//') ? substr($collapsed, 1) : $collapsed;
        $trimmed = rtrim($collapsed, '/');
        return $trimmed === '' ? '/' : $trimmed;
    }

    private function isActiveLocale(string $code): bool
    {
        foreach ($this->locales->enabled() as $row) {
            if ((string) ($row['code'] ?? '') === $code) {
                return true;
            }
        }
        return false;
    }

    /**
     * Requested locale first + the i18n fallback chain, deduped — mirrors the delivery
     * API's locale chain so render resolves exactly what the API would.
     *
     * @return non-empty-list<string>
     */
    private function localeChain(string $requested): array
    {
        $chain = $this->locales->fallbackChain($requested);
        array_unshift($chain, $requested);
        $out = [];
        foreach ($chain as $locale) {
            $locale = trim((string) $locale);
            if ($locale !== '') {
                $out[$locale] = $locale;
            }
        }
        return $out === [] ? [$requested] : array_values($out);
    }

    /** @param array<string,mixed> $typeRow */
    private function isPubliclyDeliverable(array $typeRow): bool
    {
        return DeliveryVisibility::isAccessible(
            (bool) ($typeRow['public_delivery'] ?? false),
            (string) ($typeRow['slug'] ?? ''),
            null, // anonymous — render never carries API-key scopes
        );
    }

    /** @return array<string,mixed> */
    private function redirect(string $location, int $status): array
    {
        return ['kind' => 'redirect', 'locale' => null, 'type' => null, 'content' => null,
            'redirect' => ['location' => $location, 'status' => $status],
            'listing' => null, 'term' => null, 'term_type' => null, 'field' => null,
            'preview' => false];
    }

    /** @return array<string,mixed> */
    private function notFound(): array
    {
        return ['kind' => 'not_found', 'locale' => null, 'type' => null, 'content' => null, 'redirect' => null,
            'listing' => null, 'term' => null, 'term_type' => null, 'field' => null,
            'preview' => false];
    }
}
