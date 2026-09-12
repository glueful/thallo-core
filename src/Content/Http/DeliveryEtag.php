<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http;

use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;

/**
 * Computes delivery cache validators (ETag + Cache-Control + Cache-Tag) and handles
 * conditional `If-None-Match` revalidation.
 *
 * The ETag is a strong validator over the published version identity plus the response
 * selection key (field selection / expansions / sort / filter), so any change to either
 * the published content OR the requested shape produces a new tag:
 *   etag = '"' . sha1(versionUuid . '|' . selectionKey) . '"'
 *
 * For a list response there is no single version uuid, so the ETag hashes the
 * concatenation of every member's version uuid (in result order) plus the selection key.
 *
 * `Cache-Control: public, max-age=<ttl>` is emitted with the per-type TTL (delivery is
 * may be publicly readable but the responses are still cacheable). `Cache-Tag` carries the
 * surrogate keys a CDN/cache layer purges on publish: `thallo:entry:{uuid}` for each
 * member entry plus `thallo:type:{slug}` for the whole type.
 */
final class DeliveryEtag
{
    /**
     * Build the ETag for a single published row. $expanded: the sorted
     * entry:version identities of expansion targets (spec §4 P1) — a republished
     * target must change the validator, or conditionals false-304. Empty input
     * yields a validator byte-identical to the pre-expansion formula.
     *
     * @param list<string> $expanded
     */
    public function forItem(string $versionUuid, string $selectionKey, array $expanded = []): string
    {
        return '"' . sha1($versionUuid . $this->expandedKey($expanded) . '|' . $selectionKey) . '"';
    }

    /**
     * Build the ETag for a list response from its members' version uuids.
     *
     * @param list<string> $versionUuids in result order
     * @param list<string> $expanded sorted expansion-target identities
     */
    public function forList(array $versionUuids, string $selectionKey, array $expanded = []): string
    {
        return '"' . sha1(implode('|', $versionUuids) . $this->expandedKey($expanded) . '|' . $selectionKey) . '"';
    }

    /** @param list<string> $expanded */
    private function expandedKey(array $expanded): string
    {
        return $expanded === [] ? '' : '|x:' . implode('|', $expanded);
    }

    /**
     * True when the request's `If-None-Match` matches the computed ETag.
     */
    public function matches(Request $request, string $etag): bool
    {
        $ifNoneMatch = $request->headers->get('If-None-Match');
        if ($ifNoneMatch === null || $ifNoneMatch === '') {
            return false;
        }
        foreach (array_map('trim', explode(',', $ifNoneMatch)) as $candidate) {
            if ($candidate === $etag || $candidate === 'W/' . $etag || $candidate === '*') {
                return true;
            }
        }
        return false;
    }

    /**
     * A bodyless 304 Not Modified carrying the validator + cache headers.
     */
    public function notModified(string $etag, int $ttl, string $cacheTag, bool $private = false): Response
    {
        $response = new Response();
        // setNotModified() sets 304, strips the body to '' and removes body-only headers.
        $response->setNotModified();
        $this->applyHeaders($response, $etag, $ttl, $cacheTag, $private);
        return $response;
    }

    /**
     * Apply ETag / Cache-Control / Cache-Tag headers to a built response.
     *
     * A `$private` response (one whose body depends on the caller's API-key scopes) is
     * marked `Cache-Control: private` and `Vary: X-API-Key`, so a shared cache/CDN never
     * serves a scoped-key body to an anonymous caller at the same URL. Anonymous responses
     * stay `public`. The ETag selection key already folds in a scope fingerprint (see
     * DeliveryController::selectionKey), so scoped conditional requests can't collide.
     */
    public function applyHeaders(
        Response $response,
        string $etag,
        int $ttl,
        string $cacheTag,
        bool $private = false,
    ): Response {
        $response->headers->set('ETag', $etag);
        $response->headers->set('Cache-Control', ($private ? 'private' : 'public') . ', max-age=' . $ttl);
        $response->headers->set('Cache-Tag', $cacheTag);
        if ($private) {
            // Append (don't replace) so a CORS `Vary: Origin` set upstream survives.
            $response->headers->set('Vary', 'X-API-Key', false);
        }
        return $response;
    }

    /**
     * Build the `Cache-Tag` header value: a per-entry tag for each member, each
     * expansion target (spec §4 — purge must reach embedding pages), plus the type
     * tag. Deduped, order preserved.
     *
     * @param list<string> $entryUuids
     * @param list<string> $expandedEntryUuids
     */
    public function cacheTag(array $entryUuids, string $typeSlug, array $expandedEntryUuids = []): string
    {
        $tags = [];
        foreach ([...$entryUuids, ...$expandedEntryUuids] as $uuid) {
            if ($uuid !== '') {
                $tags['thallo:entry:' . $uuid] = true;
            }
        }
        $tags['thallo:type:' . $typeSlug] = true;
        return implode(', ', array_keys($tags));
    }
}
