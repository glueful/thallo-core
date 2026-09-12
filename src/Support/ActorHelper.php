<?php

declare(strict_types=1);

namespace Thallo\Core\Support;

use Glueful\Auth\UserIdentity;
use Symfony\Component\HttpFoundation\Request;

/**
 * Resolves the acting user's uuid from a request.
 *
 * Reads the optional `auth.user` {@see UserIdentity} (set only when the auth enricher is bound)
 * first, then falls back to the **always-present** post-auth `user` array attribute. Reading
 * `auth.user` alone silently drops the actor — and thus audit attribution — on any install where
 * the enricher is not wired, which is the default. Every actor/principal extraction should go
 * through here so the dual-read is consistent.
 */
final class ActorHelper
{
    public static function uuidFromRequest(Request $request): ?string
    {
        $user = $request->attributes->get('auth.user');
        if ($user instanceof UserIdentity) {
            $uuid = trim($user->uuid());
            return $uuid === '' ? null : $uuid;
        }

        $raw = $request->attributes->get('user');
        if (is_array($raw) && isset($raw['uuid']) && is_string($raw['uuid']) && trim($raw['uuid']) !== '') {
            return trim($raw['uuid']);
        }

        return null;
    }
}
