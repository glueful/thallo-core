<?php

declare(strict_types=1);

namespace Thallo\Core\Http\Controllers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\I18n\Contracts\LocaleManagerInterface;
use Glueful\Helpers\Utils;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Contracts\Layouts\LayoutSurface;
use Thallo\Contracts\Layouts\LayoutSurfaceRegistry;
use Thallo\Contracts\Style\StyleClassProvider;
use Thallo\Core\Content\Layouts\LayoutRepository;
use Thallo\Core\Content\Layouts\LayoutTargets;
use Thallo\Core\Content\Layouts\LayoutValidator;
use Thallo\Core\Content\Preview\LayoutPreviewStore;
use Thallo\Core\Content\Preview\LayoutPreviewToken;
use Thallo\Core\Content\Preview\PreviewMinter;
use Thallo\Core\Content\Preview\PreviewTokenException;
use Thallo\Core\Content\Preview\ResolvesPreviewKey;
use Thallo\Core\Content\Validation\ValidationException;
use Thallo\Core\Http\DTOs\ApplyLayoutData;
use Thallo\Core\Http\DTOs\LayoutSessionData;

/**
 * A layout's editing sessions (type layouts spec §5.2, §5.3). A session pins a baseline — the saved
 * layout, or the starter when there is none, with the version read now — and keeps its own working
 * copy; applies build that copy by compare-and-set. Nothing here writes a layout: that is Save.
 */
final class LayoutPreviewController
{
    use ResolvesPreviewKey;

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly LayoutSurfaceRegistry $surfaces,
        private readonly LayoutRepository $layouts,
        private readonly LayoutValidator $validator,
        private readonly LayoutPreviewStore $store,
        private readonly PreviewMinter $minter,
        private readonly LocaleManagerInterface $locales,
        private readonly ?StyleClassProvider $styleClasses = null,
        /** The targets as the site can use them; null reads the surface's own. */
        private readonly ?LayoutTargets $targets = null,
    ) {
    }

    /** POST /v1/admin/layouts/preview/session */
    #[ApiOperation(
        summary: 'Open a layout editing session',
        description: 'Mints a layout preview token for a surface and target, pins the saved layout (or '
            . 'the starter when there is none) and its version as the session baseline, and picks the '
            . 'sample the stage renders it against: the one asked for, the newest published item, or '
            . 'a placeholder built in memory. The session names the surface\'s required blocks, its '
            . 'palette and its loops (each loop\'s card field and the blocks only a card holds). A target '
            . 'whose pages are off the site opens only while it keeps a saved layout, to remove it: '
            . '`closed` says why, and Save and apply refuse it. Requires `templates.manage`.',
        tags: ['Thallo Layouts'],
    )]
    #[ApiResponse(200, description: 'Session opened.')]
    #[ApiResponse(422, description: 'An unknown surface, or a target that cannot have a layout and keeps none.')]
    public function session(LayoutSessionData $input): Response
    {
        $this->styleClasses?->refresh();
        $surface = $this->surfaces->get($input->surface);
        if ($surface === null) {
            return Response::validation(['surface' => "unknown layout surface '{$input->surface}'"]);
        }
        $target = $this->targets?->find($surface, $input->target) ?? self::target($surface, $input->target);
        $row = $this->layouts->find($input->surface, $input->target);
        // A closed target opens only while it keeps a saved layout — to remove it (Save and apply stay
        // refused); with none there is nothing to open.
        $closed = null;
        if ($target === null || !$target['enabled']) {
            $closed = $target['reason'] ?? "'{$input->target}' cannot have a layout";
            if ($row === null || $row['blocks'] === null) {
                return Response::validation(['target' => $closed]);
            }
        }
        $starter = $row === null || $row['blocks'] === null;
        $layout = [
            'blocks' => $starter ? self::withIds($surface->starter($input->target)) : $row['blocks'],
            'settings' => $starter ? [] : $row['settings'],
        ];
        $lockVersion = $row['lock_version'] ?? 0;
        $sample = $closed === null ? $this->pickSample($surface, $input->target, $input->sample) : null;

        $ttl = $this->minter->ttlSeconds();
        $exp = time() + $ttl;
        $session = bin2hex(random_bytes(12));
        $token = LayoutPreviewToken::mint(
            $session,
            $input->surface,
            $input->target,
            $sample['id'] ?? null,
            $this->locales->default(),
            $exp,
            $this->previewKey($this->context),
        );
        $this->store->putBaseline($session, [
            'layout' => $layout,
            'lock_version' => $lockVersion,
            'surface' => $input->surface,
            'target' => $input->target,
            'sample' => $sample['id'] ?? null,
        ], $exp);

        $renderEnabled = app($this->context, CapabilityRegistry::class)->isEnabled('thallo.render');
        return Response::success([
            'token' => $token,
            'expires_at' => date('c', $exp),
            'expires_in' => $ttl,
            'theme_url' => $renderEnabled ? '/_preview/' . $token : null,
            'epoch' => null,
            'revision' => null,
            'layout' => $layout + ['lock_version' => $lockVersion],
            'starter' => $starter,
            // What Reset to starter puts back, whatever the baseline.
            'starter_layout' => $starter ? $layout['blocks'] : self::withIds($surface->starter($input->target)),
            'required' => $surface->required($input->target),
            'palette' => $surface->palette(),
            'loops' => $surface->loops($input->target),
            'sample' => $sample,
            'placeholder' => $sample === null,
            'label' => $surface->label($input->target),
            'reach' => $surface->reach($input->target),
            // Why this layout's pages are off the site, when they are: it can be removed, not edited.
            'closed' => $closed,
            'style_generation' => $this->styleClasses?->snapshot()->generation ?? 0,
        ], 'Layout editing session opened.');
    }

    /** POST /v1/admin/layouts/preview/apply */
    #[ApiOperation(
        summary: 'Apply a layout to its editing session',
        description: 'Validates the layout as a save would, then accepts it into the session\'s working '
            . 'copy by compare-and-set. Never writes a layout. Requires `templates.manage`.',
        tags: ['Thallo Layouts'],
    )]
    #[ApiResponse(200, description: 'Accepted.')]
    #[ApiResponse(409, description: 'The working copy moved on (PREVIEW_REVISION_STALE).')]
    #[ApiResponse(410, description: 'The session expired (LAYOUT_SESSION_EXPIRED) or its layout was removed '
        . '(LAYOUT_SESSION_RETIRED).')]
    #[ApiResponse(422, description: 'The layout is invalid.')]
    public function apply(ApplyLayoutData $input): Response
    {
        $this->styleClasses?->refresh();
        try {
            $claims = LayoutPreviewToken::verify($input->token, $this->previewKey($this->context), time());
        } catch (PreviewTokenException $e) {
            return $e->isExpired()
                ? Response::error('Preview link expired', 410, ['code' => 'LAYOUT_SESSION_EXPIRED'])
                : Response::forbidden('Invalid preview token');
        }
        if ($this->store->isRetired($claims->session)) {
            return self::retired();
        }
        $baseline = $this->store->baseline($claims->session);
        if ($baseline === null) {
            return Response::error('The editing session expired.', 410, ['code' => 'LAYOUT_SESSION_EXPIRED']);
        }
        if (strlen((string) json_encode($input->layout)) > 1_048_576) {
            return Response::error('The layout is too large to preview.', 413);
        }
        $blocks = is_array($input->layout['blocks'] ?? null) ? array_values($input->layout['blocks']) : [];
        $settings = is_array($input->layout['settings'] ?? null) ? $input->layout['settings'] : [];
        $before = is_array($baseline['layout']['blocks'] ?? null) ? $baseline['layout']['blocks'] : [];
        try {
            // No style-class guard on an apply: it persists nothing; the Save that would does.
            $clean = $this->validator->validate($claims->surface, $claims->target, $blocks, $settings, $before, false);
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
        if (($result['retired'] ?? false) === true) {
            return self::retired();
        }
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
            'fragments' => null,
        ], 'Layout applied to the stage.');
    }

    private static function retired(): Response
    {
        return Response::error('This layout was removed.', 410, ['code' => 'LAYOUT_SESSION_RETIRED']);
    }

    /** @return array{id: string, label: string}|null the sample asked for when published, else the newest */
    private function pickSample(LayoutSurface $surface, string $target, ?string $requested): ?array
    {
        $samples = $surface->samples($target, null);
        foreach ($samples as $sample) {
            if ($requested !== null && $sample['id'] === $requested) {
                return $sample;
            }
        }
        return $samples[0] ?? null;
    }

    /** @return array{target: string, label: string, enabled: bool, reason: ?string, link: ?string}|null */
    private static function target(LayoutSurface $surface, string $target): ?array
    {
        foreach ($surface->targets() as $row) {
            if ($row['target'] === $target) {
                return $row;
            }
        }
        return null;
    }

    /**
     * A starter tree with an id on every block, as the editor stores one.
     *
     * @param list<array<string,mixed>> $tree
     * @return list<array<string,mixed>>
     */
    private static function withIds(array $tree): array
    {
        $out = [];
        foreach ($tree as $block) {
            $block['id'] = Utils::generateNanoID(12);
            foreach (is_array($block['data'] ?? null) ? $block['data'] : [] as $field => $value) {
                if (is_array($value) && array_is_list($value) && isset($value[0]['type'])) {
                    $block['data'][$field] = self::withIds($value);
                }
            }
            $block['settings'] ??= [];
            $out[] = $block;
        }
        return $out;
    }
}
