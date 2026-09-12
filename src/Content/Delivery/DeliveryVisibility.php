<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Delivery;

use Glueful\Auth\ApiKey\ApiKeyService;

/**
 * The single source of truth for delivery read visibility.
 *
 * A content type is readable by a request when it opts into public delivery, or the
 * request's granted API-key scopes satisfy `read:content` (all types) or
 * `read:content:{slug}` (that type) — fnmatch semantics via
 * {@see ApiKeyService::scopeSatisfies}, so `read:content:*` behaves as delivery expects.
 * An authenticated key with an empty scope list has full access (framework convention).
 *
 * `$grantedScopes === null` means anonymous (no `api_key_scopes` request attribute).
 *
 * Shared by {@see DeliveryAccessMiddleware} (gates the URL type) and
 * {@see ReferenceResolver} (gates each referenced target type), so an anonymous or
 * narrowly-scoped caller can never pull a non-public type's fields in through a
 * reference on a type it IS allowed to read.
 */
final class DeliveryVisibility
{
    /** @param list<string>|null $grantedScopes null = anonymous. */
    public static function isAccessible(bool $publicDelivery, string $typeSlug, ?array $grantedScopes): bool
    {
        if ($grantedScopes !== null && ApiKeyService::scopeSatisfies($grantedScopes, 'read:content')) {
            return true;
        }
        if ($publicDelivery) {
            return true;
        }
        return $grantedScopes !== null
            && ApiKeyService::scopeSatisfies($grantedScopes, 'read:content:' . $typeSlug);
    }
}
