<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Layouts;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Events\EventService;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Thallo\Contracts\Delivery\RenderedPageCachePurge;
use Thallo\Contracts\Layouts\LayoutChanged;
use Thallo\Contracts\Layouts\LayoutSurfaceRegistry;
use Thallo\Tenancy\System\SystemFlags;

/**
 * What every change to a stored layout sets off once it has committed (type layouts spec §7.4): the
 * resolver forgets its answer, the rendered pages the surface declares are purged, and
 * {@see LayoutChanged} tells packs whose pages live in a cache of their own. Every writer — Save,
 * Remove, the block-document source, content-model changes — calls it from an after-commit callback.
 *
 * Only a surface's declared page tags are purged: a surface with none (the shop's product page)
 * issues no rendered-page purge at all, so a driver without tag invalidation never answers its
 * change by dropping every rendered page. A surface no pack registers any more (its capability off)
 * purges no rendered page either; the event still goes out.
 */
final class LayoutChanges
{
    public function __construct(
        private readonly LayoutResolver $resolver,
        private readonly LayoutSurfaceRegistry $surfaces,
        private readonly ?RenderedPageCachePurge $purge = null,
        private readonly ?EventService $events = null,
        private readonly ?SystemFlags $flags = null,
        private readonly ?CurrentTenantResolver $tenants = null,
        private readonly ?ApplicationContext $context = null,
    ) {
    }

    public function announce(string $surface, string $target): void
    {
        $this->resolver->forget($surface, $target);
        $tags = $this->surfaces->get($surface)?->pageTags($target) ?? [];
        if ($tags !== []) {
            $this->purge?->purge($tags);
        }
        $this->events?->dispatch(new LayoutChanged($surface, $target, $this->tenant()));
    }

    /** The workspace the write ran for — as the resolver's cache key names it — or null without tenancy. */
    private function tenant(): ?string
    {
        if ($this->flags?->tenancyEnabled() !== true || $this->tenants === null || $this->context === null) {
            return null;
        }
        $uuid = $this->tenants->tenantUuid($this->context);
        return $uuid === '' ? null : $uuid;
    }
}
