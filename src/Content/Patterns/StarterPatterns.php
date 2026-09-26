<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Patterns;

/**
 * The patterns Thallo ships: ready-made SECTIONS and starter PAGES made of them.
 *
 * A pattern is a tree of ordinary blocks with ordinary settings — nothing here is a new kind of
 * thing, and once inserted a section is simply the author's blocks, to edit, restyle and
 * rearrange. A section is always ONE block (a container, a hero, a call to action), so it is
 * inserted, moved and deleted as one. A page is a list of sections, by slug: the pages cannot
 * drift from the sections they are made of.
 *
 * Sections are built the way the structure picker's Section preset builds one (padding, a
 * content width, a flex column, a header group), so a library section and a hand-made one are the
 * same construction. Copy is placeholder copy: short, plain, and obviously the author's to replace.
 * Nothing names an image — a fresh site has no media — so every pattern is complete as inserted.
 *
 * {@see PatternLibrary} resolves these against the site's block types; PatternLibraryTest holds
 * every one of them to the validation a page save runs.
 */
final class StarterPatterns
{
    /** @return list<array{slug:string,label:string,category:string,description:string,block:array<string,mixed>}> */
    public static function sections(): array
    {
        return [
            self::section(
                'hero-centered',
                'Centred hero',
                'Hero',
                'A headline, a supporting line and two buttons on the theme’s gradient.',
                self::hero(
                    [
                        'headline' => 'Introducing Acme',
                        'title' => 'A clear headline that says what you do',
                        'description' => 'One or two sentences on who it is for and why it matters. Keep it short '
                            . 'enough to read at a glance.',
                        'heading_level' => 'h1',
                    ],
                    ['Get started', 'Learn more']
                )
            ),
            self::section(
                'hero-highlights',
                'Hero with highlights',
                'Hero',
                'The headline on one side, three short highlights on the other.',
                self::hero(
                    [
                        'headline' => 'Why Acme',
                        'title' => 'Everything your team needs, in one place',
                        'description' => 'Say what changes for the reader once they start. Then let the highlights '
                            . 'carry the detail.',
                        'orientation' => 'horizontal',
                        'heading_level' => 'h1',
                        // The hero's aside gives its children no gap of its own: a stack does.
                        'aside' => [self::stack([
                            self::feature(
                                'zap',
                                'Quick to start',
                                'Set up in an afternoon, not a quarter.',
                                'plain',
                                'horizontal',
                            ),
                            self::feature(
                                'shield-check',
                                'Safe by default',
                                'Sensible settings from the first day.',
                                'plain',
                                'horizontal',
                            ),
                            self::feature(
                                'users',
                                'Made for teams',
                                'Roles, reviews and a shared history.',
                                'plain',
                                'horizontal',
                            ),
                        ])],
                    ],
                    ['Start free']
                )
            ),
            self::section(
                'hero-inverted',
                'Dark hero',
                'Hero',
                'A dark band with one call to action, for a page that should open boldly.',
                self::hero(
                    [
                        'title' => 'A bold statement on a dark ground',
                        'description' => 'One sentence that earns the click.',
                        'background' => 'inverted',
                        'heading_level' => 'h1',
                    ],
                    ['Talk to us']
                )
            ),
            self::section(
                'page-header',
                'Page header',
                'Hero',
                'A quiet title band for an inner page: About, Pricing, Contact.',
                self::hero(
                    [
                        'title' => 'Page title',
                        'description' => 'A sentence that tells the reader what this page covers.',
                        'background' => 'muted',
                        'heading_level' => 'h1',
                    ],
                    []
                )
            ),

            self::section(
                'features-grid',
                'Feature grid',
                'Features',
                'A heading and three feature cards in a row.',
                self::band([
                    self::header(
                        'Features',
                        'Built for the way you work',
                        'Three things the reader should remember about what you offer.',
                    ),
                    self::grid('3', [
                        self::feature('layers', 'First benefit', 'Say what it does for the reader, not how it works.'),
                        self::feature('sparkles', 'Second benefit', 'Keep each one to a sentence or two.'),
                        self::feature('gauge', 'Third benefit', 'Three is easy to take in; six is a list.'),
                    ]),
                ])
            ),
            self::section(
                'features-six',
                'Six features',
                'Features',
                'A heading and six plain features in two rows.',
                self::band([
                    self::header('Everything included', 'All the parts, none of the assembly', null),
                    self::grid('3', [
                        self::feature('pen-tool', 'Design', 'A short line about this part.', 'plain'),
                        self::feature('code', 'Build', 'A short line about this part.', 'plain'),
                        self::feature('rocket', 'Launch', 'A short line about this part.', 'plain'),
                        self::feature('bar-chart-3', 'Measure', 'A short line about this part.', 'plain'),
                        self::feature('life-buoy', 'Support', 'A short line about this part.', 'plain'),
                        self::feature('lock', 'Security', 'A short line about this part.', 'plain'),
                    ]),
                ])
            ),
            self::section(
                'features-split',
                'Features beside text',
                'Features',
                'An introduction on the left, a stack of features on the right.',
                self::band(
                    [
                        self::stack([
                            self::heading('Why teams choose us', 'h2', 'start'),
                            self::text(
                                '<p>Use this side for the argument: the problem, and how you see it. The features '
                                    . 'beside it are the proof.</p>',
                                'start',
                                'color.muted',
                            ),
                            self::button('See how it works', 'solid'),
                            ], 'start'),
                        self::stack([
                            self::feature('check', 'A reason', 'One line of evidence.', 'plain', 'horizontal'),
                            self::feature('check', 'A second reason', 'One line of evidence.', 'plain', 'horizontal'),
                            self::feature('check', 'A third reason', 'One line of evidence.', 'plain', 'horizontal'),
                        ]),
                    ],
                    self::splitAtLg()
                )
            ),
            self::section('steps', 'How it works', 'Features', 'Three numbered steps in a row.', self::band([
                self::header('How it works', 'Up and running in three steps', null),
                self::grid('3', [
                    self::step('1', 'Sign up', 'Create your account in a minute.'),
                    self::step('2', 'Set up', 'Bring your content and choose a look.'),
                    self::step('3', 'Publish', 'Go live and share the link.'),
                ]),
            ])),

            self::section('stats', 'Numbers', 'Social proof', 'Four figures in a row, each with a label.', self::band([
                self::grid('4', [
                    self::stat('10k+', 'Customers'),
                    self::stat('99.9%', 'Uptime'),
                    self::stat('24/7', 'Support'),
                    self::stat('4.9/5', 'Average rating'),
                    ], '2'),
                ], [], 'color.surface-2')),
            self::section(
                'testimonials',
                'Testimonials',
                'Social proof',
                'A heading and three quotes from customers.',
                self::band([
                    self::header('Testimonials', 'What our customers say', null),
                    self::grid('3', [
                        self::quote(
                            '“It paid for itself in the first month. We should have switched sooner.”',
                            'Alex Morgan',
                            'Operations lead, Northwind',
                        ),
                        self::quote(
                            '“The team picked it up without training. That never happens.”',
                            'Sam Rivera',
                            'Founder, Brightside',
                        ),
                        self::quote(
                            '“Support answers in minutes and actually fixes the problem.”',
                            'Jordan Lee',
                            'CTO, Fieldnote',
                        ),
                    ]),
                ])
            ),

            self::section(
                'pricing-plans',
                'Pricing plans',
                'Pricing',
                'A heading and three plans, the middle one highlighted.',
                self::band([
                    self::header(
                        'Pricing',
                        'Simple pricing, no surprises',
                        'Every plan includes the essentials. Change or cancel at any time.',
                    ),
                    self::block('pricing_plans', ['orientation' => 'horizontal', 'plans' => [
                        self::plan(
                            'Starter',
                            'For trying things out.',
                            '$0',
                            "1 project\nCommunity support\nBasic analytics",
                            'Start free',
                            false,
                        ),
                        self::plan(
                            'Team',
                            'For a growing team.',
                            '$29',
                            "Unlimited projects\nPriority support\nRoles and reviews\nFull analytics",
                            'Choose Team',
                            true,
                        ),
                        self::plan(
                            'Business',
                            'For the whole company.',
                            '$99',
                            "Everything in Team\nSingle sign-on\nAudit log\nA named contact",
                            'Contact sales',
                            false,
                        ),
                    ]]),
                ])
            ),

            self::section('faq', 'FAQ', 'FAQ', 'A heading and five questions that open in place.', self::band([
                self::header('FAQ', 'Questions, answered', null),
                // The accordion takes no width of its own: a container gives it a readable measure.
                self::block('container', ['element' => 'div', 'content' => [self::block('accordion', ['items' => [
                    self::question('How do I get started?', 'Say what the first step is and how long it takes.'),
                    self::question('Can I change plans later?', 'Explain what happens to their data and their bill.'),
                    self::question('Is there a free trial?', 'State the length and whether a card is needed.'),
                    self::question('How do I get support?', 'Name the channel and the hours.'),
                    self::question('Can I cancel at any time?', 'Say yes or no first, then the detail.'),
                    ]])]], [
                        'width' => ['base' => self::token('width.content')],
                        'alignment' => ['self' => ['base' => self::choice('center')]],
                ]),
            ])),

            self::section(
                'cta-band',
                'Call to action',
                'Call to action',
                'A filled band with a line and two buttons, to close a page.',
                self::band([
                    self::cta(
                        'Ready to get started?',
                        'Join the teams already using Acme. It takes a minute to set up.',
                        'solid',
                        'vertical',
                        ['Get started', 'Talk to sales'],
                    ),
                ])
            ),
            self::section(
                'cta-split',
                'Call to action, split',
                'Call to action',
                'The line on one side and the button on the other.',
                self::band([
                    self::cta(
                        'Have a project in mind?',
                        'Tell us about it and we will reply within a day.',
                        'outline',
                        'horizontal',
                        ['Contact us'],
                    ),
                ])
            ),

            self::section(
                'about-story',
                'Our story',
                'Content',
                'Text on one side and three supporting cards on the other.',
                self::band(
                    [
                        self::stack([
                            self::heading('Our story', 'h2', 'start'),
                            self::text(
                                '<p>Tell the reader where you started and what you noticed that others had '
                                    . 'missed.</p><p>Then say what you believe, in a sentence someone could disagree '
                                    . 'with. It reads as conviction rather than copy.</p>',
                                'start',
                            ),
                            ], 'start'),
                        self::stack([
                            self::card('compass', 'Our mission', 'What you are here to do, in one sentence.'),
                            self::card('heart', 'Our values', 'How you behave when it costs you something.'),
                            self::card('map-pin', 'Where we are', 'A city, a region, or “everywhere”.'),
                        ]),
                    ],
                    self::splitAtLg()
                )
            ),
            self::section(
                'blog-latest',
                'Latest posts',
                'Content',
                'A heading and your three newest posts.',
                self::band([
                    self::header('Blog', 'Latest from the blog', null),
                    self::block(
                        'blog_posts',
                        ['type' => 'post', 'limit' => 3, 'order' => 'newest', 'columns' => '3', 'variant' => 'outline'],
                    ),
                ])
            ),

            self::section(
                'contact-form',
                'Contact form',
                'Contact',
                'How to reach you on one side, a form on the other.',
                self::band(
                    [
                        self::stack([
                            self::heading('Get in touch', 'h2', 'start'),
                            self::text(
                                '<p>Say who will read the message and how soon they reply. People write more when they '
                                    . 'know someone is there.</p>',
                                'start',
                                'color.muted',
                            ),
                            self::feature('mail', 'Email', 'hello@example.com', 'plain', 'horizontal'),
                            self::feature('phone', 'Phone', '+1 555 010 0100', 'plain', 'horizontal'),
                            ], 'start'),
                        self::block('form', [
                            'form_name' => 'Contact',
                            'delivery' => 'store_and_email',
                            'submit_label' => 'Send message',
                            'success_message' => 'Thank you. We will be in touch soon.',
                        ]),
                    ],
                    self::splitAtLg()
                )
            ),
        ];
    }

    /** @return list<array{slug:string,label:string,description:string,sections:list<string>}> */
    public static function pages(): array
    {
        return [
            [
                'slug' => 'page-landing',
                'label' => 'Landing page',
                'description' => 'A hero, features, how it works, testimonials, pricing, an FAQ and a closing '
                    . 'call to action.',
                'sections' => [
                    'hero-centered',
                    'features-grid',
                    'steps',
                    'testimonials',
                    'pricing-plans',
                    'faq',
                    'cta-band',
                ],
            ],
            [
                'slug' => 'page-about',
                'label' => 'About',
                'description' => 'A page header, your story, numbers, testimonials and a call to action.',
                'sections' => ['page-header', 'about-story', 'stats', 'testimonials', 'cta-split'],
            ],
            [
                'slug' => 'page-pricing',
                'label' => 'Pricing',
                'description' => 'A page header, the plans, an FAQ and a call to action.',
                'sections' => ['page-header', 'pricing-plans', 'faq', 'cta-band'],
            ],
            [
                'slug' => 'page-contact',
                'label' => 'Contact',
                'description' => 'A page header, a contact form and an FAQ.',
                'sections' => ['page-header', 'contact-form', 'faq'],
            ],
            [
                'slug' => 'page-services',
                'label' => 'Services',
                'description' => 'A hero with highlights, six features, how it works and a call to action.',
                'sections' => ['hero-highlights', 'features-six', 'steps', 'cta-split'],
            ],
        ];
    }

    /**
     * The header's and footer's own sections. A region lays its blocks out in one wrapping row, so
     * each of these is ONE block that takes the whole of it — a region's row holds a section as a
     * page body holds a band.
     *
     * @return list<array{slug:string,label:string,category:string,description:string,block:array<string,mixed>,region:string}>
     */
    public static function regionSections(): array
    {
        $tagline = '<p>One line on what this site is for.</p>';
        return [
            self::regionSection(
                'header',
                'header-logo-menu-button',
                'Logo, menu and button',
                'The logo on one side; the main menu and a button on the other.',
                self::row([
                    self::logo(),
                    self::row([self::menu(), self::regionButton('Get started')], 'end', fill: false),
                ]),
            ),
            self::regionSection(
                'header',
                'header-announcement',
                'Announcement bar',
                'One short line above the header, on the accent colour.',
                self::block('container', ['element' => 'div', 'content' => [
                    self::text('<p><strong>New:</strong> a short announcement for every page.</p>', 'center'),
                ]], [
                    'spacing' => ['padding' => [
                        'top' => ['base' => self::token('spacing.xs')],
                        'bottom' => ['base' => self::token('spacing.xs')],
                    ]],
                    'colors' => [
                        'surface' => self::token('color.accent'),
                        'text' => self::token('color.accent-contrast'),
                    ],
                    'radius' => self::token('radius.md'),
                    'layout' => ['basis' => ['base' => self::choice('full')]],
                ]),
            ),
            self::regionSection(
                'header',
                'header-centred',
                'Centred logo and menu',
                'The logo above the main menu, both centred.',
                self::column([self::logo(), self::menu()]),
            ),
            self::regionSection(
                'footer',
                'footer-link-columns',
                'Link columns',
                'The logo and a line about the site, then three columns of links.',
                self::block('container', ['element' => 'div', 'content' => [
                    self::stack([self::logo(), self::text($tagline, 'start')]),
                    self::links('Product', ['Features', 'Pricing', 'Changelog']),
                    self::links('Company', ['About', 'Blog', 'Contact']),
                    self::links('Resources', ['Help', 'Privacy', 'Terms']),
                ]], ['layout' => [
                    'display' => ['base' => self::choice('grid')],
                    'columns' => ['base' => self::choice('1'), 'md' => self::choice('2'), 'lg' => self::choice('4')],
                    'gap' => [
                        'row' => ['base' => self::token('spacing.xl')],
                        'column' => ['base' => self::token('spacing.xl')],
                    ],
                    'basis' => ['base' => self::choice('full')],
                ]]),
            ),
            self::regionSection(
                'footer',
                'footer-copyright-social',
                'Copyright and social links',
                'The copyright line, this year’s, on one side; social links on the other.',
                self::row([self::copyright(), self::social()]),
            ),
            self::regionSection(
                'footer',
                'footer-tagline-social',
                'Tagline and social links',
                'The logo, a line about the site and social links, centred.',
                self::column([self::logo(), self::text($tagline, 'center'), self::social()]),
            ),
            self::regionSection(
                'footer',
                'footer-copyright',
                'Copyright line',
                'The copyright line alone, centred; the year keeps itself current.',
                self::column([self::copyright()]),
            ),
        ];
    }

    /**
     * Whole headers and footers: a region's sections, in order. Inserted, a template replaces the
     * region's blocks — after the editor asks, and one undo puts them back.
     *
     * @return list<array{slug:string,label:string,category:string,description:string,sections:list<string>,region:string}>
     */
    public static function regionTemplates(): array
    {
        return [
            [
                'slug' => 'header-classic',
                'label' => 'Classic header',
                'category' => 'Header',
                'description' => 'An announcement bar over the logo, the main menu and a button.',
                'sections' => ['header-announcement', 'header-logo-menu-button'],
                'region' => 'header',
            ],
            [
                'slug' => 'header-simple',
                'label' => 'Simple header',
                'category' => 'Header',
                'description' => 'The logo, the main menu and a button, in one row.',
                'sections' => ['header-logo-menu-button'],
                'region' => 'header',
            ],
            [
                'slug' => 'header-centred-stack',
                'label' => 'Centred header',
                'category' => 'Header',
                'description' => 'The logo above the main menu, both centred.',
                'sections' => ['header-centred'],
                'region' => 'header',
            ],
            [
                'slug' => 'footer-columns',
                'label' => 'Four-column footer',
                'category' => 'Footer',
                'description' => 'The logo and three columns of links over the copyright and social links.',
                'sections' => ['footer-link-columns', 'footer-copyright-social'],
                'region' => 'footer',
            ],
            [
                'slug' => 'footer-simple',
                'label' => 'Simple footer',
                'category' => 'Footer',
                'description' => 'The logo, a line about the site and social links over the copyright line.',
                'sections' => ['footer-tagline-social', 'footer-copyright'],
                'region' => 'footer',
            ],
        ];
    }

    // ── Construction ────────────────────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $block
     * @return array{slug:string,label:string,category:string,description:string,block:array<string,mixed>}
     */
    private static function section(
        string $slug,
        string $label,
        string $category,
        string $description,
        array $block,
    ): array {
        return compact('slug', 'label', 'category', 'description', 'block');
    }

    /**
     * @param array<string,mixed> $block
     * @return array{slug:string,label:string,category:string,description:string,block:array<string,mixed>,region:string}
     */
    private static function regionSection(
        string $region,
        string $slug,
        string $label,
        string $description,
        array $block,
    ): array {
        return [...self::section($slug, $label, ucfirst($region), $description, $block), 'region' => $region];
    }

    /**
     * A region's row: its blocks side by side, spread apart and wrapping on a phone. `$fill` has it
     * take the whole of the region's row (a section); off, it sits inside another row.
     *
     * @param list<array<string,mixed>> $content
     * @return array<string,mixed>
     */
    private static function row(array $content, string $justify = 'between', bool $fill = true): array
    {
        $layout = [
            'display' => ['base' => self::choice('flex')],
            'direction' => ['base' => self::choice('row')],
            'wrap' => ['base' => self::choice('wrap')],
            'align_items' => ['base' => self::choice('center')],
            'gap' => [
                'row' => ['base' => self::token('spacing.sm')],
                'column' => ['base' => self::token('spacing.lg')],
            ],
        ];
        if ($fill) {
            $layout['basis'] = ['base' => self::choice('full')];
        }
        return self::block('container', ['element' => 'div', 'content' => $content], [
            'layout' => $layout,
            'alignment' => ['content' => ['base' => self::choice($justify)]],
        ]);
    }

    /**
     * A region's centred column, taking the whole of its row.
     *
     * @param list<array<string,mixed>> $content
     * @return array<string,mixed>
     */
    private static function column(array $content): array
    {
        return self::block('container', ['element' => 'div', 'content' => $content], ['layout' => [
            'display' => ['base' => self::choice('flex')],
            'direction' => ['base' => self::choice('column')],
            'align_items' => ['base' => self::choice('center')],
            'gap' => ['row' => ['base' => self::token('spacing.sm')]],
            'basis' => ['base' => self::choice('full')],
        ]]);
    }

    /** @return array<string,mixed> */
    private static function logo(): array
    {
        return self::block('logo', ['size' => 'medium', 'link_home' => true]);
    }

    /** The main menu — the one a fresh site is seeded with. */
    /** @return array<string,mixed> */
    private static function menu(): array
    {
        return self::block('navigation', ['menu' => 'main']);
    }

    /** @return array<string,mixed> */
    private static function regionButton(string $label): array
    {
        return self::block(
            'button',
            ['label' => $label, 'url' => '#', 'variant' => 'solid', 'color' => 'primary', 'size' => 'md'],
        );
    }

    /**
     * @param list<string> $labels
     * @return array<string,mixed>
     */
    private static function links(string $title, array $labels): array
    {
        return self::block('links', [
            'title' => $title,
            'items' => array_map(static fn (string $label): array => ['label' => $label, 'url' => '#'], $labels),
        ]);
    }

    /** The theme's copyright line: the year is rendered, so it rolls over by itself. */
    /** @return array<string,mixed> */
    private static function copyright(): array
    {
        return self::block('shortcode', ['name' => 'copyright', 'params' => []]);
    }

    /** @return array<string,mixed> */
    private static function social(): array
    {
        $link = static fn (string $brand, string $label, string $url): array
            => self::block('social_link', ['icon' => 'brand:' . $brand, 'url' => $url, 'label' => $label]);
        return self::block('social_links', ['items' => [
            $link('x', 'X', 'https://x.com'),
            $link('instagram', 'Instagram', 'https://instagram.com'),
            $link('github', 'GitHub', 'https://github.com'),
            $link('youtube', 'YouTube', 'https://youtube.com'),
        ]]);
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $style
     * @return array<string,mixed>
     */
    private static function block(string $type, array $data, array $style = []): array
    {
        return ['type' => $type, 'data' => $data, 'settings' => $style === [] ? [] : ['style' => $style]];
    }

    /** @return array{type:string,value:string} */
    private static function token(string $value): array
    {
        return ['type' => 'token', 'value' => $value];
    }

    /** @return array{type:string,value:string} */
    private static function choice(string $value): array
    {
        return ['type' => 'choice', 'value' => $value];
    }

    /**
     * A section band, as the structure picker's Section preset builds it: vertical padding, the
     * container width, a flex column with a gap.
     *
     * @param list<array<string,mixed>> $content
     * @param array<string,mixed> $layout layout declarations over the band's own
     * @return array<string,mixed>
     */
    private static function band(array $content, array $layout = [], ?string $surface = null): array
    {
        $style = [
            'spacing' => ['padding' => [
                'top' => ['base' => self::token('spacing.3xl')],
                'bottom' => ['base' => self::token('spacing.3xl')],
            ]],
            'layout' => array_replace([
                'content_width' => ['base' => self::token('width.container')],
                'display' => ['base' => self::choice('flex')],
                'direction' => ['base' => self::choice('column')],
                'gap' => ['row' => ['base' => self::token('spacing.xl')]],
            ], $layout),
        ];
        if ($surface !== null) {
            $style['colors'] = ['surface' => self::token($surface)];
        }
        return self::block('container', ['element' => 'section', 'content' => $content], $style);
    }

    /** Two tracks from `lg` up, a column below it (the Section split preset's arrangement). */
    /** @return array<string,mixed> */
    private static function splitAtLg(): array
    {
        return [
            'display' => ['base' => self::choice('flex'), 'lg' => self::choice('grid')],
            'columns' => ['lg' => self::choice('2')],
            'align_items' => ['lg' => self::choice('center')],
            'gap' => [
                'row' => ['base' => self::token('spacing.xl')],
                'column' => ['lg' => self::token('spacing.2xl')],
            ],
        ];
    }

    /**
     * @param list<array<string,mixed>> $content
     * @return array<string,mixed>
     */
    private static function stack(array $content, ?string $align = null): array
    {
        $layout = [
            'display' => ['base' => self::choice('flex')],
            'direction' => ['base' => self::choice('column')],
            'gap' => ['row' => ['base' => self::token('spacing.md')]],
        ];
        if ($align !== null) {
            $layout['align_items'] = ['base' => self::choice($align)];
        }
        return self::block('container', ['element' => 'div', 'content' => $content], ['layout' => $layout]);
    }

    /**
     * One column on a phone, `$md` from md, `$columns` from lg.
     *
     * @param list<array<string,mixed>> $content
     * @return array<string,mixed>
     */
    private static function grid(string $columns, array $content, string $md = '2'): array
    {
        return self::block('container', ['element' => 'div', 'content' => $content], ['layout' => [
            'display' => ['base' => self::choice('grid')],
            'columns' => ['base' => self::choice('1'), 'md' => self::choice($md), 'lg' => self::choice($columns)],
            'gap' => [
                'row' => ['base' => self::token('spacing.lg')],
                'column' => ['base' => self::token('spacing.lg')],
            ],
        ]]);
    }

    /** The section header group: an eyebrow, a heading and an optional lead, centred. */
    /** @return array<string,mixed> */
    private static function header(string $eyebrow, string $title, ?string $lead): array
    {
        $content = [
            self::block('rich_text', ['body' => '<p>' . $eyebrow . '</p>'], [
                'colors' => ['text' => self::token('color.accent')],
                'typography' => ['weight' => ['base' => self::choice('semibold')]],
                'alignment' => ['text' => ['base' => self::choice('center')]],
            ]),
            self::heading($title, 'h2', 'center'),
        ];
        if ($lead !== null) {
            $content[] = self::block('rich_text', ['body' => '<p>' . $lead . '</p>'], [
                'colors' => ['text' => self::token('color.muted')],
                'typography' => ['size' => ['base' => self::token('typography.size.lg')]],
                'width' => ['base' => self::token('width.content')],
                'alignment' => [
                    'text' => ['base' => self::choice('center')],
                    'self' => ['base' => self::choice('center')],
                ],
            ]);
        }
        return self::block('container', ['element' => 'div', 'content' => $content], ['layout' => [
            'display' => ['base' => self::choice('flex')],
            'direction' => ['base' => self::choice('column')],
            'gap' => ['row' => ['base' => self::token('spacing.sm')]],
        ]]);
    }

    /** @return array<string,mixed> */
    private static function heading(string $text, string $level, string $align): array
    {
        return self::block('heading', ['text' => $text, 'level' => $level], [
            'alignment' => ['text' => ['base' => self::choice($align)]],
        ]);
    }

    /** @return array<string,mixed> */
    private static function text(string $html, string $align, ?string $color = null): array
    {
        $style = ['alignment' => ['text' => ['base' => self::choice($align)]]];
        if ($color !== null) {
            $style['colors'] = ['text' => self::token($color)];
        }
        return self::block('rich_text', ['body' => $html], $style);
    }

    /** @return array<string,mixed> */
    private static function button(string $label, string $variant): array
    {
        return self::block(
            'button',
            ['label' => $label, 'url' => '#', 'variant' => $variant, 'color' => 'primary', 'size' => 'lg'],
        );
    }

    /**
     * @param array<string,mixed> $data
     * @param list<string> $buttons the first is solid, the rest outlined
     * @return array<string,mixed>
     */
    private static function hero(array $data, array $buttons): array
    {
        $links = [];
        foreach ($buttons as $i => $label) {
            $links[] = self::button($label, $i === 0 ? 'solid' : 'outline');
        }
        return self::block('hero', $data + ['links' => $links]);
    }

    /** @return array<string,mixed> */
    private static function feature(
        string $icon,
        string $title,
        string $description,
        string $variant = 'outline',
        string $orientation = 'vertical',
    ): array {
        return self::block('feature', compact('icon', 'title', 'description', 'variant', 'orientation'));
    }

    /** @return array<string,mixed> */
    private static function step(string $number, string $title, string $description): array
    {
        return self::block('feature', [
            'title' => $title,
            'description' => $description,
            'marker' => 'number',
            'number' => $number,
            'marker_background' => 'accent',
            'marker_color' => 'accent-contrast',
            'variant' => 'plain',
            'orientation' => 'vertical',
        ]);
    }

    /** @return array<string,mixed> */
    private static function stat(string $figure, string $label): array
    {
        return self::stack([
            self::block('heading', ['text' => $figure, 'level' => 'h3'], [
                'alignment' => ['text' => ['base' => self::choice('center')]],
                'typography' => ['size' => ['base' => self::token('typography.size.2xl')]],
                'colors' => ['text' => self::token('color.accent')],
            ]),
            self::text('<p>' . $label . '</p>', 'center', 'color.muted'),
        ]);
    }

    /** @return array<string,mixed> */
    private static function quote(string $quote, string $name, string $role): array
    {
        return self::block('card', [
            'variant' => 'soft',
            'orientation' => 'vertical',
            'description' => $quote,
            'body' => [
                self::block('rich_text', ['body' => '<p><strong>' . $name . '</strong><br />' . $role . '</p>']),
            ],
        ]);
    }

    /** @return array<string,mixed> */
    private static function card(string $icon, string $title, string $description): array
    {
        return self::block('card', [
            'icon' => $icon,
            'title' => $title,
            'description' => $description,
            'variant' => 'outline',
            'orientation' => 'horizontal',
            'body' => [],
        ]);
    }

    /** @return array<string,mixed> */
    private static function plan(
        string $title,
        string $description,
        string $price,
        string $features,
        string $button,
        bool $highlight,
    ): array {
        return self::block('pricing_plan', [
            'title' => $title,
            'description' => $description,
            'price' => $price,
            'billing_cycle' => '/month',
            'features' => $features,
            'button_label' => $button,
            'button_url' => '#',
            'button_variant' => $highlight ? 'solid' : 'outline',
            'variant' => 'outline',
            'highlight' => $highlight,
            'orientation' => 'vertical',
        ] + ($highlight ? ['badge' => 'Most popular'] : []));
    }

    /** @return array<string,mixed> */
    private static function question(string $question, string $answer): array
    {
        return self::block('accordion_item', ['question' => $question, 'answer' => '<p>' . $answer . '</p>']);
    }

    /**
     * @param list<string> $buttons
     * @return array<string,mixed>
     */
    private static function cta(
        string $title,
        string $description,
        string $variant,
        string $orientation,
        array $buttons,
    ): array {
        $links = [];
        foreach ($buttons as $i => $label) {
            // On a filled band the first button inverts to stay visible; the theme handles it.
            $links[] = self::button($label, $i === 0 ? 'solid' : 'outline');
        }
        return self::block('cta', compact('title', 'description', 'variant', 'orientation', 'links'));
    }
}
