<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Preview;

/**
 * Raised when a verified preview token names a draft or version that does not
 * exist (or a pinned version that does not belong to the token's entry+locale).
 *
 * Distinct from PreviewTokenException: the token itself was valid and unexpired,
 * but the content it points at is gone or out of bounds. The controller maps this
 * to 404, whereas PreviewTokenException maps to 403/410.
 *
 * The reader fails closed: it NEVER falls back to published content on this path.
 */
final class PreviewNotFoundException extends \RuntimeException
{
}
