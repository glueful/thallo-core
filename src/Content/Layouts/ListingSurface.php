<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Layouts;

use Thallo\Contracts\Layouts\CollectionPage;
use Thallo\Contracts\Layouts\LayoutSampleContext;
use Thallo\Contracts\Layouts\LayoutSurface;
use Thallo\Core\Content\Delivery\DeliveryVisibility;
use Thallo\Core\Content\Delivery\EnginePublicRouteResolver;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Schema\ContentTypeSchema;
use Thallo\Core\Settings\GeneralSettings;

/**
 * The listing surface (type layouts spec §1, §3; plan B): one layout for every page of a type's
 * listing (`/{type}[/page/n]`). A row for every publicly delivered type, open while the type is
 * listed (Settings › General › Public listings) and pointing there while it is not. Its sample is
 * the listing's first page; with nothing listed, an empty page with one sample entry.
 */
final class ListingSurface implements LayoutSurface, LayoutSampleContext
{
    public const KEY = 'listing';

    /** The loop every listing and archive layout holds, and the entry field blocks its card takes. */
    public const LOOP = 'entry_loop';

    /** @var list<string> the entry field blocks that read a card's item (spec §4, "In a card") */
    public const CARD_BLOCKS = [
        'entry_title', 'entry_date', 'entry_cover', 'entry_excerpt', 'entry_terms', 'entry_field',
    ];

    /** Where a type's listing pages are turned on (the reason's link). */
    public const SETTINGS_LINK = '/settings/general';

    public function __construct(
        private readonly ContentTypeRepository $types,
        private readonly GeneralSettings $settings,
        private readonly EnginePublicRouteResolver $resolver,
        private readonly EntrySurface $entries,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(string $target): string
    {
        return $this->name($target) . ' — listing pages';
    }

    public function reach(string $target): string
    {
        return 'Applies to every page of the ' . EntrySurface::singular($this->name($target)) . ' listing';
    }

    public function targets(): array
    {
        $out = [];
        foreach ($this->types->all() as $type) {
            $slug = (string) ($type['slug'] ?? '');
            if ($slug === '' || !self::deliverable($type)) {
                continue;
            }
            $out[] = ['target' => $slug, 'label' => $this->label($slug)] + $this->openness($slug);
        }
        return $out;
    }

    public function samples(string $target, ?string $query): array
    {
        $vars = $this->sampleContext($target, '1');
        return $vars === null || $vars['items'] === [] ? [] : [['id' => '1', 'label' => 'Page 1']];
    }

    public function defaultSample(string $target): ?string
    {
        return $this->samples($target, null)[0]['id'] ?? null;
    }

    public function sampleContext(string $target, string $sample): ?array
    {
        $path = '/' . rawurlencode($target);
        $result = $this->resolver->resolvePath($path);
        if (($result['kind'] ?? null) !== 'listing') {
            return null;
        }
        $vars = CollectionPage::context($result, $path);
        // A page with nothing on it — every post unpublished since the session began — samples
        // nothing: the stage falls back to the placeholder and its one card.
        return $vars['items'] === [] ? null : $vars;
    }

    public function placeholder(string $target): array
    {
        return CollectionPage::placeholder(
            $target,
            $this->name($target),
            $this->entries->placeholder($target),
            typeListing: $this->resolver->defaultTypeListing($target),
        );
    }

    public function palette(): array
    {
        return [self::LOOP, 'pagination', 'listing_title', ...self::CARD_BLOCKS];
    }

    public function required(string $target): array
    {
        return [['type' => self::LOOP]];
    }

    public function loops(string $target): array
    {
        return [['type' => self::LOOP, 'card' => 'card', 'items' => self::CARD_BLOCKS]];
    }

    public function bindable(string $target): array
    {
        return $this->entries->bindable($target);
    }

    public function frame(): string
    {
        return 'layouts/listing.twig';
    }

    /** Every listing page of a delivered type carries its surface tag (spec §7.4). */
    public function pageTags(string $target): array
    {
        return ["thallo:layout:listing:{$target}"];
    }

    public function starter(string $target): array
    {
        $type = $this->types->findBySlug($target);
        return $type === null
            ? []
            : Starters::forListing(ContentTypeSchema::fromArray((array) ($type['schema'] ?? [])));
    }

    /**
     * Whether a type's listing pages are on: its row's `enabled`, `reason` and `link`.
     *
     * @return array{enabled: bool, reason: ?string, link: ?string}
     */
    public function openness(string $typeSlug): array
    {
        if (in_array($typeSlug, $this->settings->listingTypes(), true)) {
            return ['enabled' => true, 'reason' => null, 'link' => null];
        }
        return [
            'enabled' => false,
            'reason' => 'Listing pages are off for ' . $this->name($typeSlug) . '.',
            'link' => self::SETTINGS_LINK,
        ];
    }

    private function name(string $typeSlug): string
    {
        return (string) ($this->types->findBySlug($typeSlug)['name'] ?? $typeSlug);
    }

    /** @param array<string,mixed> $type */
    private static function deliverable(array $type): bool
    {
        return DeliveryVisibility::isAccessible(
            (bool) ($type['public_delivery'] ?? false),
            (string) ($type['slug'] ?? ''),
            null,
        );
    }
}
