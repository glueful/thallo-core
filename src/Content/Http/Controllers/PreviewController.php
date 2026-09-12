<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\Controllers;

use Thallo\Core\Content\Http\DTOs\MintPreviewData;
use Thallo\Core\Content\Http\DTOs\Responses\Preview\PreviewMintData;
use Thallo\Core\Content\Http\DTOs\Responses\Preview\PreviewResultData;
use Thallo\Core\Content\Localization\ContentLocaleService;
use Thallo\Core\Content\Preview\PreviewMinter;
use Thallo\Core\Content\Preview\PreviewNotFoundException;
use Thallo\Core\Content\Preview\PreviewReader;
use Thallo\Core\Content\Preview\PreviewTokenException;
use Thallo\Core\Http\DTOs\ErrorResponse;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Http\Response;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Contracts\Delivery\PreviewThemeValidator;
use Thallo\Render\Theme\ThemeColors;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The narrow preview door (V1_DESIGN §6). Two endpoints, two trust models:
 *
 *  - mint()  POST /v1/admin/entries/{uuid}/preview/{locale} — permission-gated at the
 *    route (`content_permission:content.view`). Issues a short-lived HMAC-signed
 *    token bound to one {entry, locale, ?version}. Minting is authoritative-free (the
 *    reader validates existence), so the actor identity is not needed here — the route
 *    middleware is the gate.
 *
 *  - show()  GET /v1/preview/{token} — UNAUTHENTICATED by design: the token IS the
 *    capability. It carries no user, so the route is rate-limited by IP instead. Every
 *    token problem fails CLOSED: the catch arms are exhaustive, so a malformed, forged,
 *    or expired token can never yield a 200 or a partial read. Error messages are generic
 *    ("Invalid preview token" / "expired") and never leak signature internals.
 *
 * HTTP mapping (fail-closed):
 *   expired            -> 410 Gone
 *   invalid / malformed-> 403 Forbidden
 *   target not found   -> 404 Not Found
 *   valid              -> 200 { preview: {...} }
 */
final class PreviewController
{
    /**
     * @param PreviewMinter $minter issues signed, expiring tokens (mint side)
     * @param PreviewReader $reader verifies a token and resolves its target (read side)
     */
    public function __construct(
        private readonly PreviewMinter $minter,
        private readonly PreviewReader $reader,
        private readonly ContentLocaleService $locales,
        private readonly ApplicationContext $context,
        private readonly ?PreviewThemeValidator $themeValidator = null,
    ) {
    }

    /**
     * Mint a preview token for one entry+locale. The route's permission middleware is
     * the access gate; an optional `version_uuid` in the body pins a historical version.
     */
    #[ApiOperation(
        summary: 'Mint a short-lived preview token',
        description: 'The returned token is the bearer capability for the unauthenticated '
            . '`GET /v1/preview/{token}`. An optional `version_uuid` pins a historical version instead of '
            . 'the current draft.',
        tags: ['Thallo Admin'],
    )]
    #[ApiResponse(200, schema: PreviewMintData::class, description: 'Preview token minted.')]
    // 401/403/429/500 inferred from middleware + documentation.errors config.
    public function mint(MintPreviewData $input, Request $request, string $uuid, string $locale): Response
    {
        if (($errors = $this->locales->validate($locale)) !== []) {
            return Response::validation($errors);
        }
        // Per-preview theme (preview-sessions spec §5): validated through the
        // render-owned contract ONLY if bound — a theme with no validator (render
        // pack absent) is as invalid as an unknown one.
        $theme = $input->theme !== null && $input->theme !== '' ? $input->theme : null;
        if ($theme !== null) {
            if ($this->themeValidator === null || !$this->themeValidator->isValidTheme($theme)) {
                return Response::validation([
                    'theme' => 'Unknown theme (or rendered delivery is unavailable).',
                ]);
            }
        }

        // Per-preview appearance (theme-color-config spec §6): closed enums, signed
        // into the token. Token-only — never writes GeneralSettings; Save does that.
        $accent = $input->accent !== null && $input->accent !== '' ? $input->accent : null;
        $neutral = $input->neutral !== null && $input->neutral !== '' ? $input->neutral : null;
        if ($accent !== null && ThemeColors::normalizeAccent($accent) === null) {
            return Response::validation(['accent' => 'unknown accent color']);
        }
        if ($neutral !== null && ThemeColors::normalizeNeutral($neutral) === null) {
            return Response::validation(['neutral' => 'unknown neutral color']);
        }

        // version_uuid is optional: absent means "mint from the current draft". Existence /
        // ownership of a pinned version is validated by the reader at read time (domain rule).
        $token = $this->minter->mint($uuid, $locale, $input->version_uuid, $theme, $accent, $neutral);
        $ttl = $this->minter->ttlSeconds();
        $exp = time() + $ttl;

        // theme_url: the SERVER decides (preview spec §4) — null when thallo.render is
        // disabled or the pack is absent (isEnabled covers both); the JSON preview URL
        // is unaffected either way. The SPA never builds theme URLs.
        $renderEnabled = app($this->context, CapabilityRegistry::class)->isEnabled('thallo.render');

        return Response::success([
            'token' => $token,
            'expires_at' => date('c', $exp),
            'expires_in' => $ttl,
            'theme_url' => $renderEnabled ? '/_preview/' . $token : null,
        ], 'Preview token minted.');
    }

    /**
     * Verify a preview token and return the one draft (or pinned version) it names.
     * Public + rate-limited (no auth). Fails closed on every token/target problem.
     */
    #[ApiOperation(
        summary: 'Read a draft via a signed preview token',
        description: 'Unauthenticated — the token in the path is the only credential, and this is the only '
            . 'way to read unpublished content. Returns the draft, or the version the token pins.',
        tags: ['Thallo Preview'],
    )]
    #[ApiResponse(200, schema: PreviewResultData::class, description: 'The previewed draft (or pinned version).')]
    #[ApiResponse(
        403,
        schema: ErrorResponse::class,
        envelope: false,
        description: 'Invalid or malformed preview token.',
    )]
    #[ApiResponse(
        404,
        schema: ErrorResponse::class,
        envelope: false,
        description: 'The token\'s target entry/version no longer exists.',
    )]
    #[ApiResponse(410, schema: ErrorResponse::class, envelope: false, description: 'The preview token has expired.')]
    // 429/500 inferred from middleware + documentation.errors config; 403 above is a domain
    // fail-closed response on this unauthenticated route, so it is NOT inferred and stays explicit.
    public function show(Request $request, string $token): Response
    {
        try {
            $payload = $this->reader->read($token);
        } catch (PreviewTokenException $e) {
            // Exhaustive: expired -> 410 Gone, every other token fault -> 403 Forbidden.
            // Generic messages — never disclose signature/shape internals.
            return $e->isExpired()
                ? Response::error('Preview link expired', 410)
                : Response::forbidden('Invalid preview token');
        } catch (PreviewNotFoundException) {
            // Valid, unexpired token but the draft/version it names is gone or out of bounds.
            return Response::notFound('Preview target not found');
        }

        return Response::success(['preview' => $payload], 'Preview retrieved.');
    }
}
