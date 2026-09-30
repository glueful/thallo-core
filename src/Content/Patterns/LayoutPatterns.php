<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Patterns;

use Thallo\Contracts\Patterns\LayoutSection;
use Thallo\Contracts\Patterns\LayoutTarget;
use Thallo\Contracts\Patterns\LayoutTemplate;
use Thallo\Contracts\Patterns\PatternBlocks as B;
use Thallo\Core\Content\Layouts\Starters;
use Thallo\Core\Content\Schema\ContentTypeSchema;
use Thallo\Core\Content\Schema\FieldDefinition;

/**
 * Core's sections and templates for the layout editor (sections and templates design §3, §6): the
 * single post, listing and archive layouts. Each is built for the layout's target from the target
 * type's schema — the primary body is the type's own, a cover part exists only for a type with a
 * cover — or not built at all when it does not fit. The templates close to today's page are today's
 * starters ({@see Starters}).
 */
final class LayoutPatterns
{
    /** Every layout surface a pattern may name; the product and shop ones exist while Commerce is on. */
    public const SURFACES = ['entry', 'listing', 'archive', 'product', 'shop_index', 'shop_category'];

    /** The target as a builder sees it: the type's schema fields exactly as stored, nothing dropped. */
    public static function targetFor(string $surface, string $target, ?array $schema): LayoutTarget
    {
        return new LayoutTarget($surface, $target, array_values(array_filter(
            $schema ?? [],
            static fn ($field): bool => is_array($field) && isset($field['name'], $field['type']),
        )));
    }

    /** @return array<string,string> every core layout pattern's slug => its surface */
    public static function slugs(): array
    {
        $out = [];
        foreach ([...self::sections(), ...self::templates()] as $pattern) {
            $out[$pattern->slug] = $pattern->surface;
        }
        return $out;
    }

    /** @return list<LayoutSection> */
    public static function sections(): array
    {
        return [
            new LayoutSection(
                'entry-article-header',
                'entry',
                'Article header',
                'Article',
                'The title with the date, and the categories above it when the type has them.',
                static function (LayoutTarget $t): ?array {
                    $f = self::fields($t);
                    if ($f['title'] === null) {
                        return null;
                    }
                    return self::stack(array_values(array_filter([
                        $f['reference'] === null ? null : self::block('entry_terms', [
                            'field' => $f['reference'], 'style' => 'badges', 'link' => true,
                        ]),
                        self::block('entry_title', ['level' => 'h1']),
                        self::block('entry_date', ['format' => 'long']),
                    ])));
                },
            ),
            new LayoutSection(
                'entry-cover-band',
                'entry',
                'Cover band',
                'Article',
                'The cover picture in a band of its own. Offered to types with a cover.',
                static function (LayoutTarget $t): ?array {
                    $cover = self::fields($t)['cover'];
                    return $cover === null
                        ? null
                        : B::band([self::block('entry_cover', ['field' => $cover, 'aspect' => '16:9'])]);
                },
            ),
            new LayoutSection(
                'entry-related',
                'entry',
                'Related posts',
                'Article',
                'A heading over three related entries, as cards.',
                static fn (): array => B::band([
                    B::heading('Keep reading', 'h2', 'start'),
                    self::block('entry_related', ['count' => 3, 'style' => 'cards']),
                ]),
            ),
            new LayoutSection(
                'entry-neighbours',
                'entry',
                'Previous / next',
                'Article',
                'Links to the previous and the next entry.',
                static fn (): array => self::block('entry_neighbours', [
                    'previous_label' => 'Previous', 'next_label' => 'Next',
                ]),
            ),
            new LayoutSection(
                'listing-header',
                'listing',
                'Listing header',
                'Listing',
                'The listing’s title and an intro line.',
                static fn (): array => self::stack([
                    self::block('listing_title', ['level' => 'h1']),
                    B::text('<p>Everything published here, newest first.</p>', 'start', 'color.muted'),
                ]),
            ),
            new LayoutSection(
                'listing-page-nav',
                'listing',
                'Page navigation bar',
                'Listing',
                'Links to the newer and older pages, with the page count, in a band.',
                static fn (): array => B::band([self::block('pagination', [
                    'count' => true, 'previous_label' => 'Newer', 'next_label' => 'Older',
                ])]),
            ),
            new LayoutSection(
                'archive-term-header',
                'archive',
                'Term header',
                'Archive',
                'The term’s title and its description.',
                static fn (): array => self::termHeader(),
            ),
        ];
    }

    /** @return list<LayoutTemplate> */
    public static function templates(): array
    {
        return [
            new LayoutTemplate(
                'entry-classic',
                'entry',
                'Classic article',
                'Today’s single post: the title, the date and the cover above the content, related entries below.',
                static function (LayoutTarget $t): ?array {
                    $tree = Starters::forSchema(self::schema($t));
                    return $tree === [] ? null : $tree;
                },
            ),
            new LayoutTemplate(
                'entry-magazine',
                'entry',
                'Magazine',
                'A centred header, the cover at the full width of the page under it, then the content.',
                static function (LayoutTarget $t): ?array {
                    $f = self::fields($t);
                    $content = self::content($f);
                    if ($f['title'] === null && $content === null) {
                        return null;
                    }
                    $header = array_values(array_filter([
                        $f['reference'] === null ? null : self::block('entry_terms', [
                            'field' => $f['reference'], 'style' => 'badges', 'link' => true,
                        ]),
                        $f['title'] === null ? null : self::block('entry_title', ['level' => 'h1']),
                        self::block('entry_date', ['format' => 'long']),
                    ]));
                    return array_values(array_filter([
                        self::centred($header),
                        $f['cover'] === null
                            ? null
                            : self::block('entry_cover', ['field' => $f['cover'], 'aspect' => '16:9']),
                        $content === null ? null : self::centred([$content]),
                        $f['article'] ? self::block('entry_related', ['count' => 3, 'style' => 'cards']) : null,
                    ]));
                },
                ['width' => 'full'],
            ),
            new LayoutTemplate(
                'entry-minimal',
                'entry',
                'Minimal',
                'The title, a short date and the content — nothing else.',
                static function (LayoutTarget $t): ?array {
                    $f = self::fields($t);
                    $content = self::content($f);
                    if ($f['title'] === null && $content === null) {
                        return null;
                    }
                    return array_values(array_filter([
                        $f['title'] === null ? null : self::block('entry_title', ['level' => 'h1']),
                        self::block('entry_date', ['format' => 'short']),
                        $content,
                    ]));
                },
            ),
            new LayoutTemplate(
                'listing-card-grid',
                'listing',
                'Card grid',
                'The entries as cards, three to a row on wide screens: the cover, the title and the excerpt.',
                static fn (LayoutTarget $t): array => [
                    self::block('listing_title', ['level' => 'h1']),
                    self::cardGrid($t),
                    self::block('pagination', ['count' => true]),
                ],
            ),
            new LayoutTemplate(
                'listing-horizontal',
                'listing',
                'Horizontal list',
                'Today’s listing: each entry a row, its cover beside its title, date and excerpt.',
                static fn (LayoutTarget $t): array => Starters::forListing(self::schema($t)),
            ),
            new LayoutTemplate(
                'listing-compact',
                'listing',
                'Compact',
                'One line per entry: its title, and its date at the end of the line.',
                static fn (): array => [
                    self::block('listing_title', ['level' => 'h1']),
                    self::compactList(),
                    self::block('pagination', ['count' => true]),
                ],
            ),
            new LayoutTemplate(
                'archive-term-grid',
                'archive',
                'Term header with grid',
                'The term’s title and description, then its entries as cards.',
                static fn (LayoutTarget $t): array => [
                    self::termHeader(),
                    self::cardGrid($t),
                    self::block('pagination', ['count' => true]),
                ],
            ),
            new LayoutTemplate(
                'archive-horizontal',
                'archive',
                'Horizontal list',
                'Today’s archive: the term’s title, then each entry a row.',
                static fn (LayoutTarget $t): array => Starters::forListing(self::schema($t)),
            ),
            new LayoutTemplate(
                'archive-compact',
                'archive',
                'Compact',
                'The term’s title, then one line per entry.',
                static fn (): array => [
                    self::block('listing_title', ['level' => 'h1']),
                    self::compactList(),
                    self::block('pagination', ['count' => true]),
                ],
            ),
        ];
    }

    private static function schema(LayoutTarget $t): ContentTypeSchema
    {
        return ContentTypeSchema::fromArray($t->fields);
    }

    /**
     * The parts of the target's schema a single post is built from — the rules {@see Starters::forSchema}
     * reads: a filterable reference field, a plain excerpt (`excerpt` or `summary`), whether the type is
     * article-like, a `title` string, the first asset field, the primary body and the first rich text.
     *
     * @return array{reference: ?string, title: ?string, cover: ?string, body: ?string, rich: ?string, article: bool}
     */
    private static function fields(LayoutTarget $t): array
    {
        $schema = self::schema($t);
        $first = static function (callable $test) use ($schema): ?string {
            foreach ($schema->fields() as $field) {
                if ($test($field)) {
                    return $field->name;
                }
            }
            return null;
        };
        $reference = $first(static fn (FieldDefinition $f): bool => $f->type === 'reference' && $f->filterable);
        $excerpt = $first(static fn (FieldDefinition $f): bool => in_array($f->name, ['excerpt', 'summary'], true)
            && ($f->type === 'string' || ($f->type === 'text' && $f->format !== 'rich')));
        return [
            'reference' => $reference,
            'title' => $first(static fn (FieldDefinition $f): bool => $f->name === 'title' && $f->type === 'string'),
            'cover' => $first(static fn (FieldDefinition $f): bool => $f->type === 'asset'),
            'body' => Starters::primaryBody($schema),
            'rich' => $first(static fn (FieldDefinition $f): bool => $f->type === 'text' && $f->format === 'rich'),
            'article' => $reference !== null || $excerpt !== null,
        ];
    }

    /**
     * The entry's content: its primary body's slot, else its first rich text, else nothing.
     *
     * @param array{body: ?string, rich: ?string} $f
     * @return array<string,mixed>|null
     */
    private static function content(array $f): ?array
    {
        if ($f['body'] !== null) {
            return self::block('entry_content', ['field' => $f['body']]);
        }
        return $f['rich'] === null ? null : self::block('entry_field', ['field' => $f['rich'], 'format' => 'rich']);
    }

    /**
     * The Entry list as a grid of cards: the cover (a `cover` asset field), the title and the excerpt
     * (a plain `excerpt` field) — the fields {@see Starters::forListing} reads.
     *
     * @return array<string,mixed>
     */
    private static function cardGrid(LayoutTarget $t): array
    {
        $schema = self::schema($t);
        $cover = $schema->field('cover');
        $excerpt = $schema->field('excerpt');
        $plain = $excerpt !== null
            && ($excerpt->type === 'string' || ($excerpt->type === 'text' && $excerpt->format !== 'rich'));
        $card = array_values(array_filter([
            $cover !== null && $cover->type === 'asset'
                ? self::block('entry_cover', ['field' => 'cover', 'aspect' => '4:3', 'link' => true])
                : null,
            self::block('entry_title', ['level' => 'h3', 'link' => true]),
            $plain ? self::block('entry_excerpt', ['field' => 'excerpt', 'clamp' => 2]) : null,
        ]));
        return B::block('entry_loop', ['card' => [self::stack($card)]], ['layout' => [
            'display' => ['base' => B::choice('grid')],
            'columns' => ['base' => B::choice('1'), 'md' => B::choice('2'), 'lg' => B::choice('3')],
            'gap' => ['column' => ['base' => B::token('spacing.lg')], 'row' => ['base' => B::token('spacing.lg')]],
        ]]);
    }

    /**
     * The Entry list as one line per entry: its title, and its date at the end of the line.
     *
     * @return array<string,mixed>
     */
    private static function compactList(): array
    {
        return self::block('entry_loop', ['card' => [B::block('container', ['element' => 'div', 'content' => [
            self::block('entry_title', ['level' => 'h3', 'link' => true]),
            self::block('entry_date', ['format' => 'short']),
        ]], [
            'layout' => [
                'display' => ['base' => B::choice('flex')],
                'direction' => ['base' => B::choice('row')],
                'align_items' => ['base' => B::choice('center')],
                'gap' => ['column' => ['base' => B::token('spacing.sm')]],
            ],
            'alignment' => ['content' => ['base' => B::choice('between')]],
        ])]]);
    }

    /** @return array<string,mixed> the term's title and its description, a small step apart */
    private static function termHeader(): array
    {
        return self::stack([
            self::block('listing_title', ['level' => 'h1']),
            self::block('term_description', []),
        ]);
    }

    /**
     * A column of `$content` a small step apart.
     *
     * @param list<array<string,mixed>> $content
     * @return array<string,mixed>
     */
    private static function stack(array $content): array
    {
        return B::block('container', ['element' => 'div', 'content' => $content], ['layout' => [
            'display' => ['base' => B::choice('flex')],
            'direction' => ['base' => B::choice('column')],
            'gap' => ['row' => ['base' => B::token('spacing.xs')]],
        ]]);
    }

    /**
     * `$content` at a reading width, centred — for a layout at the full width of the page.
     *
     * @param list<array<string,mixed>> $content
     * @return array<string,mixed>
     */
    private static function centred(array $content): array
    {
        return B::block('container', ['element' => 'div', 'content' => $content], [
            'width' => ['base' => B::token('width.content')],
            'alignment' => ['self' => ['base' => B::choice('center')]],
        ]);
    }

    /**
     * @param array<string,mixed> $data
     * @return array{type: string, data: array<string,mixed>, settings: array<string,mixed>}
     */
    private static function block(string $type, array $data): array
    {
        return ['type' => $type, 'data' => $data, 'settings' => []];
    }
}
