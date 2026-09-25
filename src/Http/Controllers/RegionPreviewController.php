<?php

declare(strict_types=1);

namespace Thallo\Core\Http\Controllers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\I18n\Contracts\LocaleManagerInterface;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Thallo\Contracts\Delivery\HomepageEntryProvider;
use Thallo\Contracts\Delivery\PublicRouteResolver;
use Thallo\Contracts\Style\StyleClassProvider;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Core\Content\Preview\PreviewMinter;
use Thallo\Core\Content\Preview\PreviewTokenException;
use Thallo\Core\Content\Preview\RegionPreviewStore;
use Thallo\Core\Content\Preview\RegionPreviewToken;
use Thallo\Core\Content\Preview\ResolvesPreviewKey;
use Thallo\Core\Content\Regions\RegionDefinitions;
use Thallo\Core\Content\Regions\RegionRepository;
use Thallo\Core\Content\Regions\RegionValidator;
use Thallo\Core\Content\Validation\ValidationException;
use Thallo\Core\Http\DTOs\ApplyRegionsData;
use Thallo\Core\Http\DTOs\RegionSessionData;

/**
 * The header & footer stage's preview sessions (regions-stage spec §4.1, §4.3). A session pins a
 * baseline of both regions and keeps its own working copy; applies build that copy by
 * compare-and-set. Nothing here writes a region: that is the batch save.
 */
final class RegionPreviewController
{
    use ResolvesPreviewKey;

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly RegionRepository $regions,
        private readonly RegionValidator $validator,
        private readonly RegionPreviewStore $store,
        private readonly PreviewMinter $minter,
        private readonly PublicRouteResolver $resolver,
        private readonly HomepageEntryProvider $homepage,
        private readonly LocaleManagerInterface $locales,
        private readonly ?StyleClassProvider $styleClasses = null,
    ) {
    }

    /** POST /v1/admin/regions/preview/session */
    #[ApiOperation(
        summary: 'Open a header & footer stage session',
        description: 'Mints a regions-stage preview token for a published page (the homepage by '
            . 'default), pins both saved regions and their versions as the session baseline, and '
            . 'returns them. Requires `content.view`.',
        tags: ['Thallo Regions'],
    )]
    #[ApiResponse(200, description: 'Session minted.')]
    public function session(RegionSessionData $input): Response
    {
        $this->styleClasses?->refresh();
        [$page, $presentation] = $this->pickPage($input->page);
        $ttl = $this->minter->ttlSeconds();
        $exp = time() + $ttl;
        $session = bin2hex(random_bytes(12));
        $key = $this->previewKey($this->context);
        $token = RegionPreviewToken::mint($session, $page, $this->defaultLocale(), $exp, $key);

        $baseline = [];
        foreach (RegionDefinitions::slugs() as $slug) {
            $row = $this->regions->find($slug);
            $baseline[$slug] = [
                'blocks' => $row['blocks'] ?? [],
                'settings' => $row['settings'] ?? [],
                'lock_version' => $row['lock_version'] ?? null,
            ];
        }
        $this->store->putBaseline($session, $baseline, $exp);

        $renderEnabled = app($this->context, CapabilityRegistry::class)->isEnabled('thallo.render');
        return Response::success([
            'token' => $token,
            'expires_at' => date('c', $exp),
            'expires_in' => $ttl,
            'theme_url' => $renderEnabled ? '/_preview/' . $token : null,
            'epoch' => null,
            'revision' => null,
            'regions' => $baseline,
            'hidden' => [
                'header' => ($presentation['header'] ?? null) === 'hidden',
                'footer' => ($presentation['footer'] ?? null) === 'hidden',
            ],
            'style_generation' => $this->styleClasses?->snapshot()->generation ?? 0,
        ], 'Regions stage session opened.');
    }

    /** POST /v1/admin/regions/preview/apply */
    #[ApiOperation(
        summary: 'Apply the header & footer to a stage session',
        description: 'Validates both regions as a save would (and block ids across them), then '
            . 'accepts them into the session\'s working copy by compare-and-set. Never writes a '
            . 'region. Requires `content.manage`.',
        tags: ['Thallo Regions'],
    )]
    #[ApiResponse(200, description: 'Accepted.')]
    #[ApiResponse(409, description: 'The working copy moved on (PREVIEW_REVISION_STALE).')]
    #[ApiResponse(410, description: 'The session expired.')]
    #[ApiResponse(422, description: 'A region is invalid, or a block id is used in both.')]
    public function apply(ApplyRegionsData $input): Response
    {
        $this->styleClasses?->refresh();
        try {
            $claims = RegionPreviewToken::verify($input->token, $this->previewKey($this->context), time());
        } catch (PreviewTokenException $e) {
            return $e->isExpired()
                ? Response::error('Preview link expired', 410)
                : Response::forbidden('Invalid preview token');
        }
        if ($this->store->baseline($claims->session) === null) {
            return Response::error('The editing session expired.', 410);
        }
        if (strlen((string) json_encode($input->regions)) > 1_048_576) {
            return Response::error('The regions are too large to preview.', 413);
        }
        try {
            $clean = $this->validator->validateBoth($input->regions);
        } catch (ValidationException $e) {
            return Response::validation($e->errors());
        }
        $ops = [];
        foreach ($input->operations ?? [] as $op) {
            if (is_array($op) && is_string($op['type'] ?? null)) {
                $ops[] = $op;
            }
        }
        $result = $this->store->accept(
            $claims->session,
            $input->epoch,
            $input->base_revision,
            $clean,
            $ops,
            $claims->expiresAt,
        );
        if (!$result['accepted']) {
            return Response::error('The preview working copy moved on.', Response::HTTP_CONFLICT, [
                'code' => 'PREVIEW_REVISION_STALE',
                'current' => $result['epoch'] === null
                    ? null
                    : ['epoch' => $result['epoch'], 'revision' => $result['revision']],
            ]);
        }
        return Response::success([
            'epoch' => $result['epoch'],
            'revision' => $result['revision'],
            'baseline' => $result['baseline'],
            'style_generation' => $this->styleClasses?->snapshot()->generation ?? 0,
            'applied_at' => $result['accepted_at'],
        ], 'Regions applied to the stage.');
    }

    /**
     * The published page to show: the requested one when it is published, else the homepage when
     * it is, else none (the stage renders its placeholder body) — with that page's presentation
     * override, which says whether it hides a region on the live site.
     *
     * @return array{0: ?string, 1: array<string,mixed>}
     */
    private function pickPage(?string $requested): array
    {
        foreach ([$requested, $this->homepage->homepageEntry()] as $candidate) {
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }
            $resolved = $this->resolver->resolveEntry($candidate);
            if (($resolved['kind'] ?? null) === 'content') {
                $presentation = $resolved['presentation'] ?? null;
                return [$candidate, is_array($presentation) ? $presentation : []];
            }
        }
        return [null, []];
    }

    private function defaultLocale(): string
    {
        return $this->locales->default();
    }
}
