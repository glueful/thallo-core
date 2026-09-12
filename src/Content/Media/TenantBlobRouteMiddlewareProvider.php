<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Media;

use Glueful\Uploader\Contracts\BlobRouteAction;
use Glueful\Uploader\Contracts\BlobRouteMiddlewareProvider;

final class TenantBlobRouteMiddlewareProvider implements BlobRouteMiddlewareProvider
{
    public function middlewareFor(BlobRouteAction $action): array
    {
        // VIEW stays anonymous-capable (public blobs serve the storefront); the framework already
        // runs `auth:optional` ahead of this contribution there.
        if ($action === BlobRouteAction::VIEW) {
            return ['tenant_profile:public,soft'];
        }

        // Every other action: `auth` MUST come first. Thallo runs UPLOADS_ACCESS=public (public
        // retrieval), so the framework's blob routes contribute NO auth middleware of their own to
        // upload/info/delete/sign — but the `admin` resolution profile requires an authenticated
        // member (it reads the `auth.user.uuid` attribute the auth middleware populates). Without
        // auth here, the tenancy pipeline denied EVERY upload (403 "Access to this tenant is
        // denied") no matter what bearer the admin SPA sent.
        return ['auth', 'tenant_profile:admin'];
    }
}
