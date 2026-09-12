<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Preview;

/**
 * Raised whenever a preview token fails verification. The three kinds are
 * distinguished by code so the controller can map them to HTTP status:
 *   - MALFORMED / INVALID_SIGNATURE -> 403 Forbidden
 *   - EXPIRED                       -> 410 Gone
 *
 * Verification fails closed: any problem (bad shape, bad signature, expired)
 * raises this exception and never returns a (partial/forged) token.
 */
final class PreviewTokenException extends \RuntimeException
{
    public const MALFORMED = 1;
    public const INVALID_SIGNATURE = 2;
    public const EXPIRED = 3;

    public static function malformed(): self
    {
        return new self('Preview token is malformed', self::MALFORMED);
    }

    public static function invalidSignature(): self
    {
        return new self('Preview token signature is invalid', self::INVALID_SIGNATURE);
    }

    public static function expired(): self
    {
        return new self('Preview token has expired', self::EXPIRED);
    }

    public function isExpired(): bool
    {
        return $this->getCode() === self::EXPIRED;
    }
}
