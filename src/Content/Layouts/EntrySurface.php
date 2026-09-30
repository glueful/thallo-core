<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Layouts;

use Glueful\Database\Connection;
use Thallo\Contracts\Layouts\LayoutSurface;
use Thallo\Core\Content\Delivery\DeliveryVisibility;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Settings\GeneralSettings;
use Thallo\Core\Content\Schema\ContentTypeSchema;
use Thallo\Core\Content\Schema\FieldDefinition;

/**
 * The entry surface (type layouts spec §3, §4.1): one layout per publicly delivered, active
 * content type, rendered around every entry of that type at its own address. What a layout must
 * place — the type's primary body, once — and the starter it opens on follow the type's schema.
 */
final class EntrySurface implements LayoutSurface
{
    public const KEY = 'entry';

    /** The field blocks this surface adds (type layouts spec §4). */
    public const PALETTE = [
        'entry_title', 'entry_date', 'entry_cover', 'entry_excerpt', 'entry_terms', 'entry_field',
        'entry_content', 'entry_neighbours', 'entry_related',
    ];

    public function __construct(
        private readonly ContentTypeRepository $types,
        private readonly Connection $db,
        /** Which types have listing pages: only theirs need "— single post" to tell the two apart. */
        private readonly ?GeneralSettings $settings = null,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(string $target): string
    {
        $type = $this->types->findBySlug($target);
        $name = (string) ($type['name'] ?? $target);
        // A type with listing pages has two layouts, told apart by what each shows; one without has
        // this one alone, named as the type: "Pages".
        $listed = in_array($target, $this->settings?->listingTypes() ?? [], true);
        return $listed ? "{$name} — single " . self::singular($name) : $name;
    }

    public function reach(string $target): string
    {
        $type = $this->types->findBySlug($target);
        return 'Applies to every ' . self::singular((string) ($type['name'] ?? $target));
    }

    public function targets(): array
    {
        $out = [];
        foreach ($this->types->all() as $type) {
            if (!self::deliverable($type)) {
                continue;
            }
            $slug = (string) $type['slug'];
            $out[] = [
                'target' => $slug, 'label' => $this->label($slug), 'enabled' => true, 'reason' => null,
                'link' => null,
            ];
        }
        return $out;
    }

    public function samples(string $target, ?string $query): array
    {
        return $this->samplesWhere($target, $query, null);
    }

    /**
     * The type's published entries, newest first, at most fifty — only those `$where` admits, when
     * given: one SQL condition on `entries` and its bindings (an archive's terms that have members,
     * restricted in the query, so older terms with members are found behind any number of newer ones
     * without, and no list of ids is bound).
     *
     * @param array{0: string, 1: list<mixed>}|null $where
     * @return list<array{id: string, label: string}>
     */
    public function samplesWhere(string $target, ?string $query, ?array $where): array
    {
        $type = $this->types->findBySlug($target);
        if ($type === null) {
            return [];
        }
        $rows = $this->db->table('entries')
            ->select(['entries.uuid', 'entry_versions.fields', 'entry_publications.published_at'])
            ->join('entry_publications', 'entry_publications.entry_uuid', '=', 'entries.uuid')
            ->join('entry_versions', 'entry_versions.uuid', '=', 'entry_publications.version_uuid')
            ->where('entries.content_type_uuid', '=', (string) $type['uuid'])
            ->where('entries.status', '=', 'active');
        if ($where !== null) {
            // Wrapped whole: the builder joins raw conditions unparenthesised.
            $rows = $rows->whereRaw('(' . $where[0] . ')', $where[1]);
        }
        $rows = $rows->orderBy('entry_publications.published_at', 'DESC')
            ->limit(200)
            ->get();
        $needle = $query === null ? '' : mb_strtolower(trim($query));
        $out = [];
        foreach ($rows as $row) {
            $uuid = (string) $row['uuid'];
            if (isset($out[$uuid])) {
                continue;
            }
            $fields = is_string($row['fields'] ?? null) ? json_decode($row['fields'], true) : ($row['fields'] ?? []);
            $label = is_array($fields) && is_string($fields['title'] ?? null) && $fields['title'] !== ''
                ? $fields['title']
                : $uuid;
            if ($needle !== '' && !str_contains(mb_strtolower($label), $needle)) {
                continue;
            }
            $out[$uuid] = ['id' => $uuid, 'label' => $label];
            if (count($out) === 50) {
                break;
            }
        }
        return array_values($out);
    }

    public function defaultSample(string $target): ?string
    {
        return $this->samples($target, null)[0]['id'] ?? null;
    }

    public function placeholder(string $target): array
    {
        $type = $this->types->findBySlug($target);
        $schema = $this->schema($target);
        $fields = [];
        foreach ($schema?->fields() ?? [] as $field) {
            $fields[$field->name] = match ($field->type) {
                'blocks' => [],
                'reference', 'asset' => $field->multiple ? [] : null,
                'number' => null,
                'boolean' => false,
                default => '',
            };
        }
        $fields['title'] = 'Sample ' . self::singular((string) ($type['name'] ?? $target));
        return [
            'uuid' => null,
            'fields' => $fields,
            'published_at' => gmdate('c'),
            'placeholder' => true,
        ];
    }

    public function palette(): array
    {
        return self::PALETTE;
    }

    public function required(string $target): array
    {
        $schema = $this->schema($target);
        $body = $schema === null ? null : Starters::primaryBody($schema);
        return $body === null ? [] : [['type' => 'entry_content', 'field' => $body]];
    }

    public function loops(string $target): array
    {
        return [];
    }

    public function bindable(string $target): array
    {
        $out = [];
        foreach ($this->schema($target)?->fields() ?? [] as $field) {
            $out[$field->name] = $field->type === 'text' && $field->format === 'rich' ? 'text:rich' : $field->type;
        }
        return $out;
    }

    public function frame(): string
    {
        return 'layouts/entry.twig';
    }

    /** Every entry page of a supported type carries its surface tag (spec §7.4). */
    public function pageTags(string $target): array
    {
        return ["thallo:layout:entry:{$target}"];
    }

    public function starter(string $target): array
    {
        $schema = $this->schema($target);
        return $schema === null ? [] : Starters::forSchema($schema);
    }

    private function schema(string $target): ?ContentTypeSchema
    {
        $type = $this->types->findBySlug($target);
        return $type === null ? null : ContentTypeSchema::fromArray((array) ($type['schema'] ?? []));
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

    /** Names read as uncountable, or already one item: kept as they are. */
    private const KEPT = ['news', 'series', 'species', 'status', 'press', 'media', 'data', 'faq'];

    /** "Posts" → "post", "Categories" → "category", "Boxes" → "box"; "News" and "Series" stay. */
    public static function singular(string $name): string
    {
        $lower = mb_strtolower(trim($name));
        if (in_array($lower, self::KEPT, true) || mb_strlen($lower) <= 3 || !str_ends_with($lower, 's')) {
            return $lower;
        }
        return match (true) {
            str_ends_with($lower, 'ies') => mb_substr($lower, 0, -3) . 'y',
            str_ends_with($lower, 'sses'), str_ends_with($lower, 'xes'), str_ends_with($lower, 'zes'),
            str_ends_with($lower, 'ches'), str_ends_with($lower, 'shes') => mb_substr($lower, 0, -2),
            str_ends_with($lower, 'ss'), str_ends_with($lower, 'us'), str_ends_with($lower, 'is') => $lower,
            default => mb_substr($lower, 0, -1),
        };
    }
}
