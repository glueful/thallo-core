<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Fonts\Http;

use Glueful\Http\Response;
use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Content\Fonts\FontLibrary;

/**
 * The framework's own `DELETE /v1/blobs/{uuid}` refuses a font library file, as the media library
 * does (block typeface spec §2.6): a family's files, current or removed, are released only by deleting
 * the family permanently. Contributed to that route by TenantBlobRouteMiddlewareProvider.
 */
final class FontLibraryBlobGuard implements RouteMiddleware
{
    public function __construct(private readonly FontLibrary $fonts)
    {
    }

    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        if (preg_match('#/blobs/([A-Za-z0-9_-]+)/?\z#', $request->getPathInfo(), $m) === 1) {
            $families = $this->fonts->familiesUsingBlob($m[1]);
            if ($families !== []) {
                return Response::error(sprintf(
                    'This file is a font in the library (%s); '
                        . 'delete the family permanently in Site › Appearance › Typefaces first.',
                    implode(', ', array_column($families, 'name')),
                ), 409);
            }
        }
        return $next($request);
    }
}
