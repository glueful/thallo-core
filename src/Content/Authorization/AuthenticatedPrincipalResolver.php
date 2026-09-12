<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Authorization;

use Glueful\Auth\UserIdentity;
use Symfony\Component\HttpFoundation\Request;

final class AuthenticatedPrincipalResolver
{
    /** @return array{uuid:string,roles:list<string>,scopes:list<string>}|null */
    public function resolve(Request $request): ?array
    {
        $user = $request->attributes->get('auth.user');
        if ($user instanceof UserIdentity) {
            $uuid = trim($user->id());
            return $uuid === '' ? null : [
                'uuid' => $uuid,
                'roles' => array_values(array_filter($user->roles(), 'is_string')),
                'scopes' => array_values(array_filter($user->scopes(), 'is_string')),
            ];
        }

        $array = $request->attributes->get('user');
        if (!is_array($array) || !is_string($array['uuid'] ?? null) || trim($array['uuid']) === '') {
            return null;
        }

        $roles = is_array($array['roles'] ?? null)
            ? array_values(array_filter($array['roles'], 'is_string'))
            : [];
        $scopes = is_array($array['claims']['scopes'] ?? null)
            ? array_values(array_filter($array['claims']['scopes'], 'is_string'))
            : [];

        return ['uuid' => trim($array['uuid']), 'roles' => $roles, 'scopes' => $scopes];
    }

    /**
     * @param array{uuid:string,roles:list<string>,scopes:list<string>} $principal
     * @return array{roles:list<string>,scopes:list<string>,jwt_claims:array<string,mixed>}
     */
    public function aegisContext(Request $request, array $principal): array
    {
        return [
            'roles' => $principal['roles'],
            'scopes' => $principal['scopes'],
            'jwt_claims' => (array) $request->attributes->get('jwt.claims'),
        ];
    }
}
