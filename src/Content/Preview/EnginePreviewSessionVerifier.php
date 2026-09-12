<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Preview;

use Glueful\Bootstrap\ApplicationContext;
use Thallo\Contracts\Delivery\PreviewSession;
use Thallo\Contracts\Delivery\PreviewSessionVerifier;

/**
 * Verifier over PreviewToken::verify with the shared key derivation — the cheap
 * (no-DB) check the session middleware and asset route run per request. Null on any
 * token problem; it NEVER throws (fail-quiet is correct here: an invalid cookie just
 * means "no session", unlike the JSON door's explicit 403/410 mapping).
 */
final class EnginePreviewSessionVerifier implements PreviewSessionVerifier
{
    use ResolvesPreviewKey;

    public function __construct(private readonly ApplicationContext $context)
    {
    }

    public function verify(string $token): ?PreviewSession
    {
        try {
            $payload = PreviewToken::verify($token, $this->previewKey($this->context), time());
        } catch (PreviewTokenException) {
            return null;
        }
        return new PreviewSession(
            $token,
            $payload->entryUuid,
            $payload->locale,
            $payload->versionUuid,
            $payload->theme,
            $payload->accent,
            $payload->neutral,
            $payload->expiresAt,
        );
    }
}
