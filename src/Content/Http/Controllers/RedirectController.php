<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\Controllers;

use Thallo\Core\Content\Delivery\DeliveryRepository;
use Thallo\Core\Content\Http\DTOs\CreateRedirectData;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\RouteRepository;
use Thallo\Core\Content\Seo\RedirectRepository;
use Thallo\Core\Http\DTOs\ErrorResponse;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

final class RedirectController
{
    public function __construct(
        private readonly ContentTypeRepository $types,
        private readonly RedirectRepository $redirects,
        private readonly RouteRepository $routes,
        private readonly DeliveryRepository $delivery
    ) {
    }

    #[ApiOperation(
        summary: 'Create a redirect for a content type',
        description: 'Adds a manual SEO redirect (301/302/308) from a source slug to a target URL or '
            . 'entry, scoped to the content type named by `slug`.',
        tags: ['Thallo Admin'],
    )]
    #[ApiResponse(201, description: 'Redirect created.')]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'Unknown content type or target.')]
    #[ApiResponse(
        409,
        schema: ErrorResponse::class,
        envelope: false,
        description: 'Source slug conflicts with a live route.',
    )]
    #[ApiResponse(
        422,
        schema: ErrorResponse::class,
        envelope: false,
        description: 'Invalid status, unsafe target URL, or ambiguous target.',
    )]
    // 401/403/429/500 inferred from middleware + documentation.errors config.
    public function store(CreateRedirectData $input, Request $request, string $slug): Response
    {
        $type = $this->types->findBySlug($slug);
        if ($type === null) {
            return Response::notFound('Content type not found.');
        }

        if (!in_array($input->status, [301, 302, 308], true)) {
            return Response::validation(['status' => 'Redirect status must be 301, 302, or 308.']);
        }

        $typeUuid = (string) $type['uuid'];
        if ($this->routes->findBySlug($typeUuid, $input->locale, $input->source_slug) !== null) {
            return Response::error('Redirect source conflicts with a live route.', 409);
        }

        $target = $this->targetData($input, $slug, $typeUuid);
        if ($target['error'] !== null) {
            return Response::validation(['target' => $target['error']]);
        }

        $uuid = $this->redirects->create(array_merge([
            'content_type_uuid' => $typeUuid,
            'locale' => $input->locale,
            'source_slug' => $input->source_slug,
            'status' => $input->status,
            'origin' => 'manual',
            'created_by' => $this->actor($request),
        ], $target['data']));

        return Response::created(
            ['redirect' => $this->present((array) $this->redirects->findByUuid($uuid))],
            'Redirect created.'
        );
    }

    #[ApiOperation(
        summary: 'List redirects for a content type',
        description: 'Returns the manual redirects defined for the content type named by `slug` '
            . '(optionally filtered by `?locale=`), each with its resolved target state (live/broken).',
        tags: ['Thallo Admin'],
    )]
    #[ApiResponse(200, description: 'Redirects retrieved.')]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'Unknown content type slug.')]
    // 401/403/429/500 inferred from middleware + documentation.errors config.
    public function index(Request $request, string $slug): Response
    {
        $type = $this->types->findBySlug($slug);
        if ($type === null) {
            return Response::notFound('Content type not found.');
        }

        $locale = $request->query->get('locale');
        $rows = $this->redirects->listForType(
            (string) $type['uuid'],
            is_string($locale) && $locale !== '' ? $locale : null
        );

        return Response::success([
            'redirects' => array_map(fn (array $row): array => $this->present($row), $rows),
        ], 'Redirects retrieved.');
    }

    #[ApiOperation(
        summary: 'Delete a redirect',
        description: 'Removes the manual redirect identified by `uuid`.',
        tags: ['Thallo Admin'],
    )]
    #[ApiResponse(200, description: 'Redirect deleted.')]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'No redirect with that UUID.')]
    // 401/403/429/500 inferred from middleware + documentation.errors config.
    public function destroy(Request $request, string $uuid): Response
    {
        unset($request);

        if ($this->redirects->findByUuid($uuid) === null) {
            return Response::notFound('Redirect not found.');
        }

        $this->redirects->deleteByUuid($uuid);

        return Response::success([], 'Redirect deleted.');
    }

    /**
     * @return array{data:array<string,mixed>,error:?string}
     */
    private function targetData(CreateRedirectData $input, string $sourceTypeSlug, string $sourceTypeUuid): array
    {
        $hasUrl = isset($input->target['url']) && is_string($input->target['url']) && $input->target['url'] !== '';
        $hasEntry = isset($input->target['entry_uuid'])
            && is_string($input->target['entry_uuid'])
            && $input->target['entry_uuid'] !== '';
        if ($hasUrl === $hasEntry) {
            return ['data' => [], 'error' => 'Target must contain exactly one of url or entry_uuid.'];
        }

        if ($hasUrl) {
            $url = (string) $input->target['url'];
            if (!$this->isSafeRedirectUrl($url)) {
                return ['data' => [], 'error' => 'Target URL is not safe.'];
            }
            return ['data' => ['target_url' => $url], 'error' => null];
        }

        $targetTypeSlug = isset($input->target['content_type']) && is_string($input->target['content_type'])
            ? $input->target['content_type']
            : $sourceTypeSlug;
        $targetType = $this->types->findBySlug($targetTypeSlug);
        if ($targetType === null) {
            return ['data' => [], 'error' => 'Target content type not found.'];
        }
        $targetTypeUuid = (string) $targetType['uuid'];
        $targetLocale = isset($input->target['locale']) && is_string($input->target['locale'])
            ? $input->target['locale']
            : $input->locale;
        $targetEntryUuid = (string) $input->target['entry_uuid'];

        if ($this->slugFor($targetEntryUuid, $targetTypeUuid, $targetLocale) === null) {
            return ['data' => [], 'error' => 'Target entry has no route for the requested content type and locale.'];
        }

        return [
            'data' => [
                'target_content_type_uuid' => $targetTypeUuid,
                'target_locale' => $targetLocale,
                'target_entry_uuid' => $targetEntryUuid,
            ],
            'error' => null,
        ];
    }

    /** @param array<string,mixed> $row */
    private function present(array $row): array
    {
        $presented = $row;
        $presented['target_state'] = $this->targetState($row);
        $presented['target'] = $this->targetIdentity($row);

        return $presented;
    }

    /** @param array<string,mixed> $row */
    private function targetState(array $row): string
    {
        if (isset($row['target_url']) && (string) $row['target_url'] !== '') {
            return 'live';
        }

        $typeUuid = (string) $row['target_content_type_uuid'];
        $locale = (string) $row['target_locale'];
        $entryUuid = (string) $row['target_entry_uuid'];
        $slug = $this->slugFor($entryUuid, $typeUuid, $locale);
        if ($slug === null) {
            return 'broken';
        }

        return $this->delivery->findPublishedByRoute($typeUuid, $locale, $slug) === null ? 'broken' : 'live';
    }

    /** @param array<string,mixed> $row */
    private function targetIdentity(array $row): ?array
    {
        if (isset($row['target_url']) && (string) $row['target_url'] !== '') {
            return null;
        }

        $typeUuid = (string) $row['target_content_type_uuid'];
        $type = $this->types->findByUuid($typeUuid);

        return [
            'content_type' => $type === null ? null : (string) $type['slug'],
            'locale' => (string) $row['target_locale'],
            'slug' => $this->slugFor((string) $row['target_entry_uuid'], $typeUuid, (string) $row['target_locale']),
        ];
    }

    private function slugFor(string $entryUuid, string $contentTypeUuid, string $locale): ?string
    {
        foreach ($this->routes->forEntry($entryUuid) as $route) {
            if (
                (string) $route['content_type_uuid'] === $contentTypeUuid
                && (string) $route['locale'] === $locale
            ) {
                return (string) $route['slug'];
            }
        }

        return null;
    }

    private function isSafeRedirectUrl(string $url): bool
    {
        if ($url === '' || trim($url) !== $url || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return false;
        }
        if (str_starts_with($url, '//')) {
            return false;
        }
        if (str_starts_with($url, '/')) {
            return true;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        return $scheme === 'http' || $scheme === 'https';
    }

    private function actor(Request $request): ?string
    {
        $user = $request->attributes->get('user');
        if (is_array($user) && isset($user['uuid']) && is_string($user['uuid'])) {
            return $user['uuid'];
        }

        return null;
    }
}
