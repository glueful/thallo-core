<?php

declare(strict_types=1);

namespace Thallo\Core\Http\Controllers;

use Thallo\Core\Settings\GeneralSettings;
use Thallo\Core\Setup\SetupService;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Serves the admin SPA's runtime config as raw JSON at the UNAUTHENTICATED
 * `GET /admin/config`. This must NOT sit behind the `/v1/admin` auth group: the SPA
 * fetches it at boot, before it has a token, to learn the API base and whether first-run setup
 * has run. Values come from `config('thallo.admin.*')` (env-overridable), so one compiled bundle
 * works across installs.
 *
 * Returns a bare JSON object (NOT the framework `data`-envelope) because the SPA reads
 * `apiBase`/`sitePreviewUrl`/`defaultLocale` at the top level — a plain config document.
 * Returns a Symfony `JsonResponse` directly; the Glueful router accepts any Symfony
 * `Response` return (`Glueful\Http\Response` extends it), so no envelope/bridge is needed.
 */
final class AdminConfigController
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly SetupService $setup,
    ) {
    }

    #[ApiOperation(
        summary: 'Admin SPA runtime config',
        description: 'Unauthenticated bootstrap config the admin SPA fetches at startup: `apiBase`, '
            . '`sitePreviewUrl`, `defaultLocale`, and whether first-run setup has completed '
            . '(`installed`). A plain JSON document (no `data` envelope) so one compiled bundle '
            . 'works across installs.',
        tags: ['Thallo Setup'],
    )]
    #[ApiResponse(200, description: 'Runtime config: apiBase, sitePreviewUrl, defaultLocale, installed, apiDocsPath.')]
    public function config(): JsonResponse
    {
        $payload = [
            'apiBase' => (string) config($this->context, 'thallo.admin.api_base', '/v1/admin'),
            'sitePreviewUrl' => app($this->context, GeneralSettings::class)->sitePreviewUrl(),
            'defaultLocale' => app($this->context, GeneralSettings::class)->defaultLocale(),
            // Whether first-run setup has run. The SPA boot guard routes to /setup when false.
            'installed' => $this->setup->isInstalled(),
            // The framework's API reference path (API_DOCS_PATH, default /api-docs): the sidebar's
            // "API Reference" link is built from it, same-origin.
            'apiDocsPath' => \Glueful\Support\Documentation\ApiDocsPath::resolve($this->context),
        ];

        // No-store: install config can change without a rebuild; the SPA must read it fresh.
        $json = new JsonResponse($payload);
        $json->headers->set('Cache-Control', 'no-store');
        return $json;
    }
}
