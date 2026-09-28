<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Layouts;

use Thallo\Core\Content\Schema\ContentTypeSchema;
use Thallo\Core\Content\Schema\FieldDefinition;

/**
 * The layout a content type's entries open on before anyone designs one (type layouts spec §2.10),
 * built from the type's schema so it is valid for every shape a type can have.
 *
 * A type is article-like when it has a filterable reference field or a plain-text field named
 * `excerpt` or `summary`. The starter is, in order: the terms (article-like only), the title (a
 * `title` string field), the date (article-like only), the excerpt (article-like only), the cover
 * (article-like only, the first asset field), the content — the primary body's slot when there is a
 * blocks field, else the first rich-text field — and related entries (article-like only). The seeded
 * post gets the design `entry/post.twig` has; the seeded page exactly its title, then its body.
 */
final class Starters
{
    /** @return list<array<string,mixed>> */
    public static function forSchema(ContentTypeSchema $schema): array
    {
        $fields = $schema->fields();
        $reference = self::first(
            $fields,
            static fn (FieldDefinition $f): bool => $f->type === 'reference' && $f->filterable,
        );
        $excerpt = self::first(
            $fields,
            static fn (FieldDefinition $f): bool => in_array($f->name, ['excerpt', 'summary'], true)
                && ($f->type === 'string' || ($f->type === 'text' && $f->format !== 'rich')),
        );
        $article = $reference !== null || $excerpt !== null;
        $title = self::first(
            $fields,
            static fn (FieldDefinition $f): bool => $f->name === 'title' && $f->type === 'string',
        );
        $cover = self::first($fields, static fn (FieldDefinition $f): bool => $f->type === 'asset');
        $body = self::primaryBody($schema);
        $rich = self::first(
            $fields,
            static fn (FieldDefinition $f): bool => $f->type === 'text' && $f->format === 'rich',
        );

        $tree = [];
        if ($article && $reference !== null) {
            $tree[] = self::block('entry_terms', ['field' => $reference->name, 'style' => 'badges', 'link' => true]);
        }
        if ($title !== null) {
            $tree[] = self::block('entry_title', ['level' => 'h1']);
        }
        if ($article) {
            $tree[] = self::block('entry_date', ['format' => 'long']);
        }
        if ($article && $excerpt !== null) {
            $tree[] = self::block('entry_excerpt', ['field' => $excerpt->name]);
        }
        if ($article && $cover !== null) {
            $tree[] = self::block('entry_cover', ['field' => $cover->name, 'aspect' => '16:9']);
        }
        if ($body !== null) {
            $tree[] = self::block('entry_content', ['field' => $body]);
        } elseif ($rich !== null) {
            $tree[] = self::block('entry_field', ['field' => $rich->name, 'format' => 'rich']);
        }
        if ($article) {
            $tree[] = self::block('entry_related', ['count' => 3, 'style' => 'list']);
        }
        return $tree;
    }

    /**
     * The layout a type's listing pages and its archive pages open on (type layouts plan B): today's
     * listing and archive page in blocks — the title, the Entry list, whose card is today's row, and
     * the page navigation. (Today's archive shows no term description; the Term description block is
     * in the archive's palette for a layout that wants it.)
     *
     * The card is block flow, so today's row is a container in it: a row holding the cover (an asset
     * field named `cover`, linked, as the row is) beside a column of the linked title, the date and
     * the excerpt (a plain-text field named `excerpt`, three lines at most) a small step apart. With
     * no cover the card holds the column alone. The theme gives a card's cover and text today's
     * sizes (blocks.css).
     *
     * @return list<array<string,mixed>>
     */
    public static function forListing(ContentTypeSchema $schema): array
    {
        $cover = $schema->field('cover');
        $excerpt = $schema->field('excerpt');
        $text = [
            self::block('entry_title', ['level' => 'h2', 'link' => true]),
            self::block('entry_date', ['format' => 'long']),
        ];
        $plain = $excerpt !== null
            && ($excerpt->type === 'string' || ($excerpt->type === 'text' && $excerpt->format !== 'rich'));
        if ($plain) {
            $text[] = self::block('entry_excerpt', ['field' => 'excerpt', 'clamp' => 3]);
        }
        $card = [
            self::container($text, ['gap' => ['row' => ['base' => ['type' => 'token', 'value' => 'spacing.xs']]]]),
        ];
        if ($cover !== null && $cover->type === 'asset') {
            $card = [self::container(
                [self::block('entry_cover', ['field' => 'cover', 'link' => true]), ...$card],
                ['direction' => ['base' => ['type' => 'choice', 'value' => 'row']]],
            )];
        }
        return [
            self::block('listing_title', ['level' => 'h1']),
            self::block('entry_loop', ['card' => $card]),
            self::block('pagination', ['count' => true]),
        ];
    }

    /**
     * A container holding `$content`, its layout settings `$layout`.
     *
     * @param list<array<string,mixed>> $content
     * @param array<string,mixed> $layout
     * @return array{type: string, data: array<string,mixed>, settings: array<string,mixed>}
     */
    private static function container(array $content, array $layout): array
    {
        return [
            'type' => 'container',
            'data' => ['element' => 'div', 'content' => $content],
            'settings' => ['style' => ['layout' => $layout]],
        ];
    }

    /** The type's primary body: the blocks field named `body`, else the first blocks field. */
    public static function primaryBody(ContentTypeSchema $schema): ?string
    {
        $blocks = array_values(array_filter(
            $schema->fields(),
            static fn (FieldDefinition $f): bool => $f->type === 'blocks',
        ));
        foreach ($blocks as $field) {
            if ($field->name === 'body') {
                return 'body';
            }
        }
        return $blocks[0]->name ?? null;
    }

    /**
     * @param list<FieldDefinition> $fields
     * @param callable(FieldDefinition): bool $test
     */
    private static function first(array $fields, callable $test): ?FieldDefinition
    {
        foreach ($fields as $field) {
            if ($test($field)) {
                return $field;
            }
        }
        return null;
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
