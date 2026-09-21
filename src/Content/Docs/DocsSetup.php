<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Docs;

use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Settings\GeneralSettings;

/**
 * "Add documentation to my site", in one step (`thallo:docs:setup`).
 *
 * A docs section is an ordinary content type and nothing more, so this only MAKES one the way
 * the docs templates and the Markdown importer expect it, and lets the site list it (the listing
 * allowlist is what turns `/docs` into an index instead of a 404):
 *
 *   title*, summary, section (enum — the sidebar's groups, in this order), order (number),
 *   body (plain text: the Markdown as written, rendered by the theme with `markdown()`),
 *   source_path (the file it came from), edit_url (where a reader can propose a change).
 *
 * The type's SLUG is the URL: `docs` serves `/docs` and `/docs/{page}`. Idempotent. A type that
 * already exists is never rewritten — a site's own type is the site's — but what the docs
 * templates need and it lacks is reported.
 */
final class DocsSetup
{
    public const DEFAULT_SECTIONS = ['getting-started', 'concepts', 'guides', 'reference', 'operations'];

    /** What `entry/docs.twig` and the sidebar cannot work without. */
    private const NEEDED = ['title', 'body', 'section', 'order'];

    public function __construct(
        private readonly ContentTypeRepository $types,
        private readonly GeneralSettings $settings,
    ) {
    }

    /**
     * @param list<string> $sections
     * @return array{created: bool, missing: list<string>, listed: bool}
     */
    public function run(string $slug, array $sections): array
    {
        if (preg_match('/\A[a-z][a-z0-9-]{0,62}\z/', $slug) !== 1) {
            throw new \InvalidArgumentException(
                "\"{$slug}\" cannot be a docs type: lower-case letters, digits and hyphens, starting with a letter.",
            );
        }
        $sections = array_values(array_unique(array_filter(
            $sections,
            static fn (string $s): bool => preg_match('/\A[a-z0-9][a-z0-9-]{0,62}\z/', $s) === 1,
        )));
        if ($sections === []) {
            throw new \InvalidArgumentException('A docs type needs at least one section (lower-case, hyphenated).');
        }

        $existing = $this->types->findBySlug($slug);
        $created = false;
        $missing = [];
        if ($existing === null) {
            $this->types->create([
                'slug' => $slug,
                'name' => ucfirst(str_replace('-', ' ', $slug)),
                'public_delivery' => true,
                'schema' => self::schema($sections),
            ]);
            $created = true;
        } else {
            $have = array_column((array) ($existing['schema'] ?? []), 'name');
            $missing = array_values(array_diff(self::NEEDED, $have));
        }

        $listed = $this->settings->listingTypes();
        $wasListed = in_array($slug, $listed, true);
        if (!$wasListed && $missing === []) {
            $this->settings->save(['listing_types' => [...$listed, $slug]]);
        }

        return ['created' => $created, 'missing' => $missing, 'listed' => !$wasListed && $missing === []];
    }

    /**
     * @param list<string> $sections
     * @return list<array<string,mixed>>
     */
    public static function schema(array $sections): array
    {
        return [
            ['name' => 'title', 'type' => 'string', 'required' => true],
            ['name' => 'summary', 'type' => 'string'],
            ['name' => 'section', 'type' => 'enum', 'enum' => $sections],
            ['name' => 'order', 'type' => 'number'],
            ['name' => 'body', 'type' => 'text', 'format' => 'plain'],
            ['name' => 'source_path', 'type' => 'string'],
            ['name' => 'edit_url', 'type' => 'string'],
        ];
    }
}
