<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Preview;

use Glueful\Bootstrap\ApplicationContext;

/**
 * Single source of truth for the preview-token signing key, shared by the minter
 * and the reader so a mint→read round trip can never diverge on key derivation.
 *
 * Mirrors the framework EncryptionService::resolveAndValidateKey() handling of
 * APP_KEY: read config('app.key') and, if it carries a `base64:` prefix, decode it
 * to the raw bytes. The only hard requirement is that mint and read produce the
 * SAME key string — both call this method, so they always do.
 */
trait ResolvesPreviewKey
{
    private function previewKey(ApplicationContext $context): string
    {
        $key = (string) config($context, 'app.key', '');
        if ($key === '') {
            throw new \RuntimeException(
                'APP_KEY is not configured; preview tokens cannot be signed.'
            );
        }
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            if ($decoded !== false) {
                $key = $decoded;
            }
        }
        return $key;
    }
}
