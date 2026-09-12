<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Preview;

use Glueful\Bootstrap\ApplicationContext;

/**
 * Mints HMAC-signed preview tokens bound to one {entry, locale, ?version} with a
 * TTL. Minting is intentionally cheap and authoritative-free: it does NOT check
 * that a draft/version exists — the token is a signed *binding*, and the reader
 * validates existence at read time. This keeps the mint endpoint fast and means a
 * token minted just before a draft is deleted simply 404s on read (fail closed).
 *
 * The signing key is derived from APP_KEY via the same accessor the framework's
 * EncryptionService uses (config('app.key'), base64: prefix decoded to raw bytes),
 * so PreviewReader — which derives the key identically — verifies what this mints.
 */
final class PreviewMinter
{
    use ResolvesPreviewKey;

    public function __construct(private readonly ApplicationContext $context)
    {
    }

    /**
     * Mint a signed token for the entry+locale (optionally pinned to a version, and
     * optionally carrying a per-preview theme — validated by the CALLER via the
     * PreviewThemeValidator contract before it reaches here).
     */
    public function mint(
        string $entryUuid,
        string $locale,
        ?string $versionUuid = null,
        ?string $theme = null,
        ?string $accent = null,
        ?string $neutral = null,
    ): string {
        return PreviewToken::mint(
            $entryUuid,
            $locale,
            $versionUuid,
            time() + $this->ttlSeconds(),
            $this->previewKey($this->context),
            $theme,
            $accent,
            $neutral,
        );
    }

    /**
     * The token lifetime in seconds. Exposed so the controller can compute and
     * return expires_at / expires_in without re-reading config.
     */
    public function ttlSeconds(): int
    {
        return (int) config($this->context, 'thallo.preview.ttl_seconds', 600);
    }
}
