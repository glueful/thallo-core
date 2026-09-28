<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Layouts;

use Glueful\Database\Connection;
use Thallo\Contracts\Layouts\CollectionPage;
use Thallo\Contracts\Layouts\LayoutSampleContext;
use Thallo\Contracts\Layouts\LayoutSurface;
use Thallo\Core\Content\Delivery\DeliveryVisibility;
use Thallo\Core\Content\Delivery\EnginePublicRouteResolver;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Schema\ContentTypeSchema;

/**
 * The archive surface (type layouts spec §1, §3; plan B): one layout for every archive of an
 * archived field (`/{type}/{field}/{term}[/page/n]`) — a filterable reference field to a delivered
 * type; its target is `{type}:{field}`. Its rows are open while the type's listing pages are on.
 * Its samples are the terms that have members; with none, an empty page for a sample term.
 */
final class ArchiveSurface implements LayoutSurface, LayoutSampleContext
{
    public const KEY = 'archive';

    public function __construct(
        private readonly ContentTypeRepository $types,
        private readonly EnginePublicRouteResolver $resolver,
        private readonly ListingSurface $listing,
        private readonly EntrySurface $entries,
        private readonly Connection $db,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(string $target): string
    {
        [$type, $field] = self::split($target);
        return $this->name($type) . ' — ' . $this->fieldLabel($type, $field) . ' archive';
    }

    public function reach(string $target): string
    {
        [$type, $field] = self::split($target);
        return 'Applies to every ' . EntrySurface::singular($this->fieldLabel($type, $field))
            . ' page of ' . $this->name($type);
    }

    public function targets(): array
    {
        $out = [];
        foreach ($this->types->all() as $type) {
            $slug = (string) ($type['slug'] ?? '');
            if ($slug === '' || !self::deliverable($type)) {
                continue;
            }
            $openness = $this->listing->openness($slug);
            foreach ($this->resolver->archivedFields($type) as $field) {
                $target = "{$slug}:{$field['name']}";
                $out[] = ['target' => $target, 'label' => $this->label($target)] + $openness;
            }
        }
        return $out;
    }

    public function samples(string $target, ?string $query): array
    {
        [$type, $field] = self::split($target);
        $archived = $this->archived($type, $field);
        $typeRow = $this->types->findBySlug($type);
        if ($archived === null || $typeRow === null || !$this->listing->openness($type)['enabled']) {
            return [];
        }
        $members = [];
        $rows = $this->db->table('published_entry_references')
            ->select(['target_entry_uuid'])
            ->where('source_content_type_uuid', '=', (string) $typeRow['uuid'])
            ->where('field', '=', $field)
            ->get();
        foreach ($rows as $row) {
            $members[(string) $row['target_entry_uuid']] = true;
        }
        return array_values(array_filter(
            $this->entries->samples($archived['target'], $query),
            static fn (array $term): bool => isset($members[$term['id']]),
        ));
    }

    public function defaultSample(string $target): ?string
    {
        return $this->samples($target, null)[0]['id'] ?? null;
    }

    public function sampleContext(string $target, string $sample): ?array
    {
        [$type, $field] = self::split($target);
        $path = '/' . rawurlencode($type) . '/' . rawurlencode($field) . '/' . rawurlencode($sample);
        $result = $this->resolver->resolvePath($path);
        if (($result['kind'] ?? null) !== 'archive') {
            return null;
        }
        // The page's own address uses the term's slug, as its links do.
        $slugField = $this->archived($type, $field)['slug_field'] ?? null;
        $slug = is_string($slugField) ? ($result['term']['fields'][$slugField] ?? null) : null;
        if (is_string($slug) && $slug !== '') {
            $path = '/' . rawurlencode($type) . '/' . rawurlencode($field) . '/' . rawurlencode($slug);
        }
        return CollectionPage::context($result, $path);
    }

    public function placeholder(string $target): array
    {
        [$type, $field] = self::split($target);
        $termType = $this->archived($type, $field)['target'] ?? null;
        $format = null;
        if ($termType !== null) {
            $termSchema = ContentTypeSchema::fromArray((array) ($this->types->findBySlug($termType)['schema'] ?? []));
            $description = $termSchema->field('description');
            $format = $description === null ? null
                : ($description->type === 'text' && $description->format === 'rich' ? 'rich' : 'plain');
        }
        return CollectionPage::placeholder(
            $type,
            $this->name($type),
            $this->entries->placeholder($type),
            ['uuid' => null, 'fields' => [
                'title' => 'Sample ' . EntrySurface::singular($this->fieldLabel($type, $field)),
            ]],
            $field,
            $this->resolver->defaultTypeListing($type),
            $format,
        );
    }

    public function palette(): array
    {
        return [...$this->listing->palette(), 'term_description'];
    }

    public function required(string $target): array
    {
        return $this->listing->required($target);
    }

    public function loops(string $target): array
    {
        return $this->listing->loops($target);
    }

    public function bindable(string $target): array
    {
        return $this->entries->bindable(self::split($target)[0]);
    }

    public function frame(): string
    {
        return 'layouts/archive.twig';
    }

    /** Every archive page of an archived field carries its surface tag (spec §7.4). */
    public function pageTags(string $target): array
    {
        return ["thallo:layout:archive:{$target}"];
    }

    public function starter(string $target): array
    {
        $type = $this->types->findBySlug(self::split($target)[0]);
        return $type === null
            ? []
            : Starters::forListing(ContentTypeSchema::fromArray((array) ($type['schema'] ?? [])));
    }

    /** @return array{0: string, 1: string} the type and the field of `{type}:{field}` */
    private static function split(string $target): array
    {
        $parts = explode(':', $target, 2);
        return [$parts[0], $parts[1] ?? ''];
    }

    /** @return array{name: string, label: string, target: string, slug_field: ?string}|null */
    private function archived(string $type, string $field): ?array
    {
        $row = $this->types->findBySlug($type);
        foreach ($row === null ? [] : $this->resolver->archivedFields($row) as $archived) {
            if ($archived['name'] === $field) {
                return $archived;
            }
        }
        return null;
    }

    private function fieldLabel(string $type, string $field): string
    {
        return $this->archived($type, $field)['label'] ?? ucfirst(str_replace('_', ' ', $field));
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
