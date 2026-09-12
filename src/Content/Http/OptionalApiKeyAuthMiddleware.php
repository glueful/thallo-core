<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http;

use Glueful\Auth\ApiKey\ApiKeyService;
use Glueful\Auth\Contracts\UserProviderInterface;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Http\Response;
use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;

/**
 * Authenticates an API key when one is present, but lets anonymous requests continue.
 *
 * Delivery access is decided by DeliveryAccessMiddleware: authenticated keys must satisfy
 * a content scope, while anonymous requests are allowed only for content types that
 * explicitly opt into public delivery. An invalid supplied key is still a hard 401 and
 * never falls through to the public path.
 */
final class OptionalApiKeyAuthMiddleware implements RouteMiddleware
{
    public function __construct(private readonly ApplicationContext $context)
    {
    }

    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        $plainKey = $this->extractApiKey($request);
        if ($plainKey === null) {
            return $next($request);
        }

        try {
            $key = ApiKeyService::verify($this->context, $plainKey, $request->getClientIp() ?? '');
            $identity = $this->context->getContainer()->get(UserProviderInterface::class)->findByUuid($key->user_uuid);
            if ($identity === null) {
                return Response::unauthorized('Invalid API key');
            }
        } catch (\Throwable) {
            return Response::unauthorized('Invalid API key');
        }

        $userData = $identity->toArray();
        $request->attributes->set('authenticated', true);
        // The post-auth `'user'` array attribute is the framework convention (AuthMiddleware
        // sets it too). Rate limiting keyed `by: 'user'` reads exactly this attribute —
        // without it, per-user limits silently degrade to per-IP.
        $request->attributes->set('user', $userData);
        $request->attributes->set('user_id', $key->user_uuid);
        $request->attributes->set('user_data', $userData);
        $request->attributes->set('auth_method', 'api_key');
        $request->attributes->set('api_key_scopes', $key->getScopes());
        $request->attributes->set('api_key_uuid', (string) $key->uuid);

        return $next($request);
    }

    private function extractApiKey(Request $request): ?string
    {
        $apiKey = $request->headers->get('X-API-Key');
        if (is_string($apiKey) && $apiKey !== '') {
            return $apiKey;
        }

        $header = $request->headers->get('Authorization');
        if (is_string($header) && str_starts_with($header, 'ApiKey ')) {
            return substr($header, 7);
        }

        return null;
    }
}
