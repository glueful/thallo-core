<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Blocks;

use Thallo\Render\Theme\ThemeColors;

/**
 * The starter block library (starter-library spec §1; expanded + hero/cta
 * reshaped by the block-library spec) — DATA ONLY, the one source of truth for
 * `thallo:blocks:seed`. Every schema passes BlockTypeRepository::create()'s
 * rules (the seeder goes through it, so the starters validate themselves). No
 * `reference` fields: reference_type targets site-specific content types.
 *
 * Conventions carried by the definitions themselves:
 * - `block_types` allowlists are picker-only (never validation).
 * - `Items` category = single-purpose child blocks for collection fields.
 * - `pattern` / `min` / `max` are schema-declared value constraints
 *   (block-library spec §5): container hex colors, shortcode names.
 * - `html` seeds DEACTIVATED (`active` => false): raw output is an explicit
 *   admin opt-in in Settings → Block types.
 */
final class StarterBlockTypes
{
    private const HEX = '#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?';

    /**
     * @return list<array{slug: string, label: string, icon: string, category: string,
     *   description: string, schema: list<array<string,mixed>>, active?: bool}>
     */
    public static function definitions(): array
    {
        return [
            // ---- Layout -----------------------------------------------------
            ['slug' => 'section', 'label' => 'Section', 'icon' => 'i-lucide-rows-3',
                'category' => 'Layout', 'description' => 'A titled band of content with a background style.',
                'schema' => [
                    ['name' => 'headline', 'type' => 'string'],
                    ['name' => 'title', 'type' => 'string'],
                    ['name' => 'description', 'type' => 'text'],
                    ['name' => 'background', 'type' => 'enum', 'enum' => ['none', 'muted', 'subtle', 'inverted']],
                    ['name' => 'orientation', 'type' => 'enum', 'enum' => ['vertical', 'horizontal']],
                    ['name' => 'reverse', 'type' => 'boolean'],
                    ['name' => 'links', 'type' => 'blocks', 'block_types' => ['button']],
                    ['name' => 'content', 'type' => 'blocks'],
                ]],
            ['slug' => 'style', 'label' => 'Style', 'icon' => 'i-lucide-palette',
                'category' => 'Layout',
                'description' => 'Re-skin a group of blocks with a chosen accent/neutral, '
                    . 'plus an optional custom-CSS class hook.',
                'schema' => [
                    ['name' => 'accent', 'type' => 'enum',
                        'enum' => array_merge(['inherit'], ThemeColors::ACCENTS)],
                    ['name' => 'neutral', 'type' => 'enum',
                        'enum' => array_merge(['inherit'], ThemeColors::NEUTRALS)],
                    ['name' => 'class_hook', 'type' => 'string',
                        'pattern' => '[A-Za-z_][A-Za-z0-9_-]*( [A-Za-z_][A-Za-z0-9_-]*)*'],
                    ['name' => 'shadow', 'type' => 'enum',
                        'enum' => ['none', '2xs', 'xs', 'sm', 'md', 'lg', 'xl', '2xl']],
                    ['name' => 'shadow_color', 'type' => 'string', 'pattern' => self::HEX],
                    ['name' => 'shadow_opacity', 'type' => 'number', 'min' => 0, 'max' => 200],
                    ['name' => 'padding', 'type' => 'enum', 'enum' => ['none', 'small', 'medium', 'large']],
                    ['name' => 'margin', 'type' => 'enum', 'enum' => ['none', 'small', 'medium', 'large']],
                    ['name' => 'content', 'type' => 'blocks'],
                ]],
            ['slug' => 'container', 'label' => 'Container', 'icon' => 'i-lucide-square-dashed',
                'category' => 'Layout',
                'description' => 'Free-form styled wrapper: background color/image, overlay, width and padding.',
                // Fields carry an editor `group` so the (now large) config folds into
                // collapsible sections in the block editor; `content` stays ungrouped so
                // the nested region is always visible. Grouping is presentation-only —
                // the render template ignores it. Fields are ordered by group so each
                // section is contiguous.
                'schema' => [
                    // ---- Background ----
                    ['name' => 'background_color', 'type' => 'string', 'pattern' => self::HEX,
                        'format' => 'color', 'group' => 'Background'],
                    ['name' => 'background_image', 'type' => 'asset', 'group' => 'Background'],
                    // A muted, looping video background (behind the overlay). Takes
                    // visual precedence over background_image when both are set.
                    ['name' => 'background_video', 'type' => 'asset', 'group' => 'Background'],
                    // Video background from a URL: a YouTube/Vimeo link (rendered as a
                    // muted cover iframe) or a direct video-file URL (native <video>).
                    // The uploaded blob above wins if both are set.
                    ['name' => 'background_video_url', 'type' => 'string', 'group' => 'Background'],
                    ['name' => 'bg_size', 'type' => 'enum', 'enum' => ['cover', 'contain', 'auto'],
                        'group' => 'Background'],
                    ['name' => 'bg_repeat', 'type' => 'enum', 'enum' => ['no-repeat', 'repeat'],
                        'group' => 'Background'],
                    ['name' => 'bg_position', 'type' => 'enum',
                        'enum' => ['center', 'top', 'bottom', 'left', 'right'], 'group' => 'Background'],
                    ['name' => 'overlay_color', 'type' => 'string', 'pattern' => self::HEX,
                        'format' => 'color', 'group' => 'Background'],
                    ['name' => 'overlay_opacity', 'type' => 'number', 'min' => 0, 'max' => 100,
                        'group' => 'Background'],
                    // ---- Layout (width/height, alignment, flex) ----
                    ['name' => 'width', 'type' => 'enum', 'enum' => ['full', 'contained', 'narrow'],
                        'group' => 'Layout'],
                    ['name' => 'max_width', 'type' => 'number', 'min' => 0, 'group' => 'Layout'],
                    ['name' => 'min_height', 'type' => 'enum', 'enum' => ['auto', 'half', 'screen'],
                        'group' => 'Layout'],
                    ['name' => 'min_height_px', 'type' => 'number', 'min' => 0, 'group' => 'Layout'],
                    // Vertical placement of the content within the container (needs a
                    // min_height to be visible). Enables the centered-hero / Cover look.
                    ['name' => 'content_align', 'type' => 'enum', 'enum' => ['top', 'center', 'bottom'],
                        'group' => 'Layout'],
                    // Flex layout (opt-in): 'flex' lays the child content out as flex items;
                    // 'block' (default) keeps normal flow. The rest apply only in flex mode.
                    ['name' => 'layout', 'type' => 'enum', 'enum' => ['block', 'flex'], 'group' => 'Layout'],
                    ['name' => 'flex_direction', 'type' => 'enum',
                        'enum' => ['row', 'column', 'row-reverse', 'column-reverse'], 'group' => 'Layout'],
                    ['name' => 'justify', 'type' => 'enum',
                        'enum' => ['start', 'center', 'end', 'between', 'around', 'evenly'], 'group' => 'Layout'],
                    ['name' => 'align_items', 'type' => 'enum',
                        'enum' => ['start', 'center', 'end', 'stretch'], 'group' => 'Layout'],
                    ['name' => 'gap', 'type' => 'number', 'min' => 0, 'group' => 'Layout'],
                    ['name' => 'flex_wrap', 'type' => 'enum', 'enum' => ['nowrap', 'wrap'], 'group' => 'Layout'],
                    // ---- Spacing (token preset + per-side px overrides) ----
                    ['name' => 'padding_preset', 'type' => 'enum', 'enum' => ['none', 'small', 'medium', 'large'],
                        'group' => 'Spacing'],
                    // Per-side px padding; overrides padding_preset when any side is set.
                    ['name' => 'padding', 'type' => 'box', 'group' => 'Spacing'],
                    ['name' => 'margin', 'type' => 'box', 'group' => 'Spacing'],
                    // ---- Border ----
                    ['name' => 'radius', 'type' => 'box', 'group' => 'Border'],
                    ['name' => 'border_style', 'type' => 'enum',
                        'enum' => ['none', 'solid', 'dashed', 'dotted'], 'group' => 'Border'],
                    ['name' => 'border_width', 'type' => 'number', 'min' => 0, 'group' => 'Border'],
                    ['name' => 'border_color', 'type' => 'string', 'pattern' => self::HEX,
                        'format' => 'color', 'group' => 'Border'],
                    // ---- Effects ----
                    ['name' => 'shadow', 'type' => 'enum',
                        'enum' => ['none', '2xs', 'xs', 'sm', 'md', 'lg', 'xl', '2xl'], 'group' => 'Effects'],
                    // Ungrouped → always-visible nested region.
                    ['name' => 'content', 'type' => 'blocks'],
                ]],
            ['slug' => 'grid', 'label' => 'Grid', 'icon' => 'i-lucide-layout-grid',
                'category' => 'Layout',
                'description' => 'A responsive wrapping grid (or masonry flow) of blocks.',
                'schema' => [
                    ['name' => 'columns', 'type' => 'enum', 'enum' => ['1', '2', '3', '4']],
                    ['name' => 'flow', 'type' => 'enum', 'enum' => ['grid', 'masonry']],
                    ['name' => 'gap', 'type' => 'enum', 'enum' => ['small', 'medium', 'large']],
                    ['name' => 'items', 'type' => 'blocks'],
                ]],
            ['slug' => 'columns', 'label' => 'Columns', 'icon' => 'i-lucide-columns-3',
                'category' => 'Layout', 'description' => 'Two or three columns of blocks.',
                'schema' => [
                    ['name' => 'layout', 'type' => 'enum', 'enum' => ['2', '3']],
                    // Ratio presets (columns-sizing spec): one flat enum for both
                    // layouts; a preset that doesn't match `layout` renders as
                    // equal columns (template allowlist guard), never an error.
                    ['name' => 'widths', 'type' => 'enum', 'enum' => [
                        '50-50', '33-67', '67-33', '25-75', '75-25',
                        '33-33-33', '25-50-25', '50-25-25', '25-25-50',
                    ]],
                    ['name' => 'align', 'type' => 'enum', 'enum' => ['stretch', 'top', 'center', 'bottom']],
                    ['name' => 'col_1', 'type' => 'blocks'],
                    ['name' => 'col_2', 'type' => 'blocks'],
                    ['name' => 'col_3', 'type' => 'blocks'],
                ]],
            ['slug' => 'navigation', 'label' => 'Navigation', 'icon' => 'i-lucide-menu',
                'category' => 'Layout',
                'description' => 'Links from a navigation menu (structured source — pick a menu, not links).',
                'schema' => [
                    ['name' => 'menu', 'type' => 'string', 'required' => true,
                        'pattern' => '[a-z0-9]+(-[a-z0-9]+)*'],
                    ['name' => 'orientation', 'type' => 'enum', 'enum' => ['horizontal', 'vertical']],
                    // RTL-safe alignment (logical): end = right in LTR, left in RTL.
                    ['name' => 'align', 'type' => 'enum', 'enum' => ['start', 'center', 'end']],
                    ['name' => 'size', 'type' => 'enum', 'enum' => ['sm', 'md', 'lg']],
                    // Style model (navigationMenu-derived, translated to our tokens):
                    // variant picks the shape (filled pill vs plain link), color the
                    // accent (brand vs monochrome), highlight the active indicator.
                    // Replaces the old hover_style/active_style pair.
                    ['name' => 'variant', 'type' => 'enum', 'enum' => ['pill', 'link']],
                    ['name' => 'color', 'type' => 'enum', 'enum' => ['primary', 'neutral']],
                    ['name' => 'highlight', 'type' => 'enum', 'enum' => ['none', 'underline', 'bar']],
                    // Submenu presentation: dropdown = one flat panel (children +
                    // grandchildren flattened); columns = megamenu grid (each child
                    // menu item becomes a column of its own children).
                    ['name' => 'submenu_layout', 'type' => 'enum', 'enum' => ['dropdown', 'columns']],
                    ['name' => 'submenu_icon', 'type' => 'enum',
                        'enum' => ['chevron-down', 'chevron-right', 'plus', 'none']],
                    ['name' => 'submenu_trigger', 'type' => 'enum', 'enum' => ['hover', 'click']],
                    // Accessible landmark label (theme-runtime spec §7): names the
                    // <nav> for assistive tech; the template defaults to 'Navigation'.
                    ['name' => 'aria_label', 'type' => 'string', 'label' => 'Navigation label (assistive)'],
                ]],
            ['slug' => 'separator', 'label' => 'Separator', 'icon' => 'i-lucide-separator-horizontal',
                'category' => 'Layout',
                'description' => 'A horizontal rule, optionally with a centered label and icon.',
                'schema' => [
                    ['name' => 'label', 'type' => 'string'],
                    ['name' => 'type', 'type' => 'enum', 'enum' => ['solid', 'dashed', 'dotted']],
                    ['name' => 'size', 'type' => 'enum', 'enum' => ['xs', 'sm', 'md', 'lg', 'xl']],
                    ['name' => 'icon', 'type' => 'string', 'pattern' => '[a-z0-9]+(-[a-z0-9]+)*', 'format' => 'icon'],
                ]],
            // Nuxt UI Footer shape (refs.md `footer`): a <footer> bar with an optional
            // top band, then left (copyright) / center (links) / right (social) slots.
            // `copyright` is a block list so it can hold a shortcode (e.g. a dynamic
            // copyright/year), rich_text, or a logo — not just static text.
            ['slug' => 'footer', 'label' => 'Footer', 'icon' => 'i-lucide-panels-top-left',
                'category' => 'Layout',
                'description' => 'A footer bar: copyright, links and social, over an optional top band.',
                'schema' => [
                    ['name' => 'top', 'type' => 'blocks'],
                    ['name' => 'copyright', 'type' => 'blocks'],
                    ['name' => 'links', 'type' => 'blocks', 'block_types' => ['links', 'navigation']],
                    ['name' => 'social', 'type' => 'blocks', 'block_types' => ['social_links']],
                ]],
            ['slug' => 'spacer', 'label' => 'Spacer', 'icon' => 'i-lucide-move-vertical',
                'category' => 'Layout', 'description' => 'Vertical breathing room.',
                'schema' => [
                    ['name' => 'size', 'type' => 'enum', 'enum' => ['small', 'medium', 'large']],
                ]],

            // ---- Content ----------------------------------------------------
            // PageHero shape (block-library spec §2b): eyebrow/title/description,
            // button links, media column, vertical (centered) | horizontal.
            ['slug' => 'hero', 'label' => 'Hero', 'icon' => 'i-lucide-sparkles',
                'category' => 'Content', 'description' => 'Big heading, supporting copy, buttons and media.',
                'schema' => [
                    ['name' => 'headline', 'type' => 'string'],
                    ['name' => 'title', 'type' => 'string', 'required' => true],
                    ['name' => 'description', 'type' => 'text'],
                    ['name' => 'links', 'type' => 'blocks', 'block_types' => ['button']],
                    ['name' => 'image', 'type' => 'asset'],
                    ['name' => 'orientation', 'type' => 'enum', 'enum' => ['vertical', 'horizontal']],
                    ['name' => 'reverse', 'type' => 'boolean'],
                    // Heading level (modern-blocks spec §4): lets a hero render as h1
                    // when it's the page's primary heading, or step down when it isn't.
                    ['name' => 'heading_level', 'type' => 'enum', 'enum' => ['h1', 'h2', 'h3']],
                ]],
            ['slug' => 'rich_text', 'label' => 'Rich text', 'icon' => 'i-lucide-text',
                'category' => 'Content', 'description' => 'Free-form formatted text.',
                'schema' => [
                    ['name' => 'body', 'type' => 'text', 'format' => 'rich'],
                ]],
            // A single heading/label — the lightweight alternative to reaching for a
            // rich_text block just to place one line. Level defaults to h2 at render
            // (h1 is the page title); align is logical (start/center/end).
            ['slug' => 'heading', 'label' => 'Heading', 'icon' => 'i-lucide-heading',
                'category' => 'Content', 'description' => 'A single heading or label line.',
                'schema' => [
                    ['name' => 'text', 'type' => 'string', 'required' => true],
                    ['name' => 'level', 'type' => 'enum', 'enum' => ['h1', 'h2', 'h3', 'h4', 'h5', 'h6']],
                    ['name' => 'align', 'type' => 'enum', 'enum' => ['start', 'center', 'end']],
                    // Freeform font color (optional) → inline `color:` at render. The
                    // 'color' format renders a swatch picker in the editor.
                    ['name' => 'color', 'type' => 'string', 'pattern' => self::HEX, 'format' => 'color'],
                ]],
            ['slug' => 'card', 'label' => 'Card', 'icon' => 'i-lucide-rectangle-horizontal',
                'category' => 'Content',
                'description' => 'A content card: icon, title, description and nested blocks.',
                'schema' => [
                    ['name' => 'icon', 'type' => 'string', 'pattern' => '[a-z0-9]+(-[a-z0-9]+)*', 'format' => 'icon'],
                    ['name' => 'title', 'type' => 'string'],
                    ['name' => 'description', 'type' => 'text'],
                    ['name' => 'variant', 'type' => 'enum',
                        'enum' => ['outline', 'solid', 'soft', 'subtle', 'ghost', 'naked']],
                    ['name' => 'orientation', 'type' => 'enum', 'enum' => ['vertical', 'horizontal']],
                    ['name' => 'reverse', 'type' => 'boolean'],
                    ['name' => 'body', 'type' => 'blocks'],
                ]],
            ['slug' => 'accordion', 'label' => 'Accordion', 'icon' => 'i-lucide-list-collapse',
                'category' => 'Content', 'description' => 'A stack of expandable question/answer items.',
                'schema' => [
                    ['name' => 'title', 'type' => 'string'],
                    ['name' => 'multiple', 'type' => 'boolean'],
                    ['name' => 'items', 'type' => 'blocks', 'block_types' => ['accordion_item']],
                ]],
            ['slug' => 'collapsible', 'label' => 'Collapsible', 'icon' => 'i-lucide-chevrons-up-down',
                'category' => 'Content',
                'description' => 'A single show/hide disclosure wrapping nested blocks.',
                'schema' => [
                    ['name' => 'label', 'type' => 'string'],
                    ['name' => 'open', 'type' => 'boolean'],
                    ['name' => 'content', 'type' => 'blocks'],
                ]],
            ['slug' => 'links', 'label' => 'Links', 'icon' => 'i-lucide-list',
                'category' => 'Content',
                'description' => 'A vertical list of navigation links with an optional title.',
                'schema' => [
                    ['name' => 'title', 'type' => 'string'],
                    ['name' => 'items', 'type' => 'json'],
                ]],
            // PageCTA shape (block-library spec §2b): title/description, the five
            // band variants, orientation/reverse, button links.
            ['slug' => 'cta', 'label' => 'Call to action', 'icon' => 'i-lucide-megaphone',
                'category' => 'Content', 'description' => 'A call-to-action band with buttons.',
                'schema' => [
                    ['name' => 'title', 'type' => 'string', 'required' => true],
                    ['name' => 'description', 'type' => 'text'],
                    ['name' => 'variant', 'type' => 'enum',
                        'enum' => ['solid', 'outline', 'soft', 'subtle', 'naked']],
                    ['name' => 'orientation', 'type' => 'enum', 'enum' => ['vertical', 'horizontal']],
                    ['name' => 'reverse', 'type' => 'boolean'],
                    ['name' => 'links', 'type' => 'blocks', 'block_types' => ['button']],
                ]],
            ['slug' => 'form', 'label' => 'Form', 'icon' => 'i-lucide-mail',
                'category' => 'Content',
                'description' => 'A contact form: stores submissions and emails a recipient.',
                'schema' => [
                    ['name' => 'form_name', 'type' => 'string'],
                    ['name' => 'recipient', 'type' => 'string', 'group' => 'Delivery'],
                    // store_and_email keeps a triageable record; email_only skips storage
                    // and just notifies the recipient.
                    ['name' => 'delivery', 'type' => 'enum',
                        'enum' => ['store_and_email', 'email_only'], 'group' => 'Delivery'],
                    ['name' => 'success_message', 'type' => 'text', 'group' => 'Delivery'],
                    ['name' => 'redirect_url', 'type' => 'string', 'group' => 'Delivery'],
                    ['name' => 'submit_label', 'type' => 'string', 'group' => 'Form'],
                    ['name' => 'submit_variant', 'type' => 'enum',
                        'enum' => ['solid', 'outline', 'soft', 'subtle', 'ghost', 'link'], 'group' => 'Form'],
                    ['name' => 'submit_color', 'type' => 'enum',
                        'enum' => ['primary', 'neutral'], 'group' => 'Form'],
                    ['name' => 'heading', 'type' => 'string', 'group' => 'Form'],
                    ['name' => 'intro', 'type' => 'text', 'group' => 'Form'],
                    ['name' => 'name_label', 'type' => 'string', 'group' => 'Fields'],
                    ['name' => 'email_label', 'type' => 'string', 'group' => 'Fields'],
                    ['name' => 'message_label', 'type' => 'string', 'group' => 'Fields'],
                    ['name' => 'include_subject', 'type' => 'boolean', 'group' => 'Fields'],
                    ['name' => 'subject_label', 'type' => 'string', 'group' => 'Fields'],
                    ['name' => 'include_phone', 'type' => 'boolean', 'group' => 'Fields'],
                    ['name' => 'phone_label', 'type' => 'string', 'group' => 'Fields'],
                    ['name' => 'phone_required', 'type' => 'boolean', 'group' => 'Fields'],
                    ['name' => 'include_consent', 'type' => 'boolean', 'group' => 'Fields'],
                    ['name' => 'consent_text', 'type' => 'string', 'group' => 'Fields'],
                ]],
            ['slug' => 'stepper', 'label' => 'Stepper', 'icon' => 'i-lucide-list-ordered',
                'category' => 'Content', 'description' => 'A numbered sequence of steps, horizontal or vertical.',
                'schema' => [
                    ['name' => 'title', 'type' => 'string'],
                    ['name' => 'orientation', 'type' => 'enum', 'enum' => ['vertical', 'horizontal']],
                    ['name' => 'color', 'type' => 'enum',
                        'enum' => ['primary', 'secondary', 'success', 'info', 'warning', 'error', 'neutral']],
                    ['name' => 'size', 'type' => 'enum', 'enum' => ['xs', 'sm', 'md', 'lg', 'xl']],
                    ['name' => 'items', 'type' => 'blocks', 'block_types' => ['stepper_item']],
                ]],
            ['slug' => 'tabs', 'label' => 'Tabs', 'icon' => 'i-lucide-panels-top-left',
                'category' => 'Content', 'description' => 'Tabbed panels of blocks.',
                'schema' => [
                    ['name' => 'items', 'type' => 'blocks', 'block_types' => ['tab']],
                ]],
            // Nuxt UI Button shape (refs.md `button`): variant + color (primary|neutral,
            // matching navigation) + size, optional leading/trailing icons, block
            // (full-width) and alignment.
            ['slug' => 'button', 'label' => 'Button', 'icon' => 'i-lucide-mouse-pointer-click',
                'category' => 'Content', 'description' => 'A standalone action button.',
                'schema' => [
                    ['name' => 'label', 'type' => 'string', 'required' => true],
                    ['name' => 'url', 'type' => 'string', 'required' => true],
                    ['name' => 'variant', 'type' => 'enum',
                        'enum' => ['solid', 'outline', 'soft', 'subtle', 'ghost', 'link']],
                    ['name' => 'color', 'type' => 'enum', 'enum' => ['primary', 'neutral']],
                    ['name' => 'size', 'type' => 'enum', 'enum' => ['xs', 'sm', 'md', 'lg', 'xl']],
                    ['name' => 'leading_icon', 'type' => 'string',
                        'pattern' => '[a-z0-9]+(-[a-z0-9]+)*', 'format' => 'icon'],
                    ['name' => 'trailing_icon', 'type' => 'string',
                        'pattern' => '[a-z0-9]+(-[a-z0-9]+)*', 'format' => 'icon'],
                    ['name' => 'block', 'type' => 'boolean'],
                    ['name' => 'align', 'type' => 'enum', 'enum' => ['left', 'center', 'right']],
                ]],
            // Color-mode switch (color-mode spec §3.5): a 3-way light/system/dark
            // segmented control. Presentation only — no data fields.
            ['slug' => 'color_mode', 'label' => 'Color mode', 'icon' => 'i-lucide-sun-moon',
                'category' => 'Content',
                'description' => 'A light / system / dark color-mode switch for visitors.',
                'schema' => []],
            ['slug' => 'carousel', 'label' => 'Carousel', 'icon' => 'i-lucide-gallery-horizontal',
                'category' => 'Content', 'description' => 'A swipeable slider — each child block is a slide.',
                'schema' => [
                    ['name' => 'slides', 'type' => 'blocks'],
                    ['name' => 'slides_per_view', 'type' => 'enum', 'enum' => ['1', '2', '3']],
                    ['name' => 'arrows', 'type' => 'boolean'],
                    ['name' => 'dots', 'type' => 'boolean'],
                    ['name' => 'autoplay', 'type' => 'boolean'],
                    // Style variant (modern-blocks spec §4): 'hero' renders a full-bleed,
                    // larger-scale slide treatment; 'default' keeps the current look.
                    ['name' => 'style', 'type' => 'enum', 'enum' => ['default', 'hero']],
                    // Transition set (slider-config ruling, 2026-08-02): slide is the
                    // scroll-snap default and the universal no-JS floor; fade cross-fades
                    // overlapped slides; zoom is the Ken Burns treatment — fade plus a
                    // restrained image-only scale. enum_labels is presentation metadata
                    // the admin select displays; stored values stay the bare enum.
                    ['name' => 'transition', 'type' => 'enum', 'enum' => ['slide', 'fade', 'zoom'],
                        'enum_labels' => ['slide' => 'Slide', 'fade' => 'Fade', 'zoom' => 'Zoom (Ken Burns)']],
                    // Seconds; ONE pace for every mode — the runtime drives the slide
                    // scroll from it (native smooth pace is UA-fixed) and fade/zoom
                    // consume it as --carousel-duration. Blank = theme defaults
                    // (native scroll pace / 1.2s cross-fade).
                    ['name' => 'transition_duration', 'type' => 'number', 'min' => 0.2, 'max' => 5],
                    // Hero height preset (same ruling): svh-based with pixel floors —
                    // compact 40svh / standard 60svh (fallback) / tall 80svh / full 100svh.
                    ['name' => 'height', 'type' => 'enum', 'enum' => ['compact', 'standard', 'tall', 'full']],
                ]],
            // Reveal heading with an optional rotating word/phrase list (modern-blocks
            // spec §3). rotate_words is newline-delimited (one alternative per line);
            // FieldValidator caps it at 5 alternatives at save time (Task 2).
            ['slug' => 'animated_text', 'label' => 'Animated text', 'icon' => 'i-lucide-type',
                'category' => 'Content',
                'description' => 'A heading with a reveal effect and an optional rotating word.',
                'schema' => [
                    ['name' => 'prefix', 'type' => 'string'],
                    // One alternative per line (phrases allowed) — at most 5 (FieldValidator cap).
                    ['name' => 'rotate_words', 'type' => 'text'],
                    ['name' => 'suffix', 'type' => 'string'],
                    ['name' => 'effect', 'type' => 'enum', 'enum' => ['fade', 'slide-up', 'blur']],
                    // Loop (2026-08 follow-up): OFF keeps the finite settle-after-one-cycle
                    // default (motion calms down on its own); ON keeps rotating — still
                    // paused offscreen/on hidden tabs, paused while hovered (so the word
                    // can be read), and fully static under reduced motion.
                    ['name' => 'loop', 'type' => 'boolean'],
                    // Seconds each word stays before rotating (2026-08 polish: the old
                    // hardcoded 1s read as too fast). Blank = 2.5s.
                    ['name' => 'interval', 'type' => 'number', 'min' => 0.5, 'max' => 10],
                    ['name' => 'tag', 'type' => 'enum', 'enum' => ['h1', 'h2', 'h3', 'p']],
                    // Per-segment styling (2026-08 follow-up): prefix / rotating words /
                    // suffix each take independent color, relative size, and weight/style.
                    // Grouped: the form folds each trio into a collapsed section.
                    ['name' => 'prefix_color', 'type' => 'string', 'format' => 'color', 'group' => 'Prefix style'],
                    ['name' => 'prefix_size', 'type' => 'enum', 'enum' => ['inherit', 'sm', 'lg', 'xl'],
                        'group' => 'Prefix style'],
                    ['name' => 'prefix_bold', 'type' => 'boolean', 'group' => 'Prefix style'],
                    ['name' => 'prefix_italic', 'type' => 'boolean', 'group' => 'Prefix style'],
                    ['name' => 'rotate_color', 'type' => 'string', 'format' => 'color',
                        'group' => 'Rotating words style'],
                    ['name' => 'rotate_size', 'type' => 'enum', 'enum' => ['inherit', 'sm', 'lg', 'xl'],
                        'group' => 'Rotating words style'],
                    ['name' => 'rotate_bold', 'type' => 'boolean', 'group' => 'Rotating words style'],
                    ['name' => 'rotate_italic', 'type' => 'boolean', 'group' => 'Rotating words style'],
                    ['name' => 'suffix_color', 'type' => 'string', 'format' => 'color', 'group' => 'Suffix style'],
                    ['name' => 'suffix_size', 'type' => 'enum', 'enum' => ['inherit', 'sm', 'lg', 'xl'],
                        'group' => 'Suffix style'],
                    ['name' => 'suffix_bold', 'type' => 'boolean', 'group' => 'Suffix style'],
                    ['name' => 'suffix_italic', 'type' => 'boolean', 'group' => 'Suffix style'],
                ]],

            // ---- Media ------------------------------------------------------
            ['slug' => 'image', 'label' => 'Image', 'icon' => 'i-lucide-image',
                'category' => 'Media', 'description' => 'A single image with caption.',
                'schema' => [
                    ['name' => 'image', 'type' => 'asset', 'required' => true],
                    ['name' => 'alt', 'type' => 'string'],
                    ['name' => 'caption', 'type' => 'string'],
                    // Layout-size preset: how wide the figure sits within the content column.
                    ['name' => 'size', 'type' => 'enum', 'enum' => ['normal', 'wide', 'full']],
                    // Explicit intrinsic dimensions in px (optional, independent of `size`).
                    // Set either or both: one alone preserves aspect ratio, both are exact.
                    ['name' => 'width', 'type' => 'number', 'min' => 1],
                    ['name' => 'height', 'type' => 'number', 'min' => 1],
                ]],
            // Responsive image grid (modern-blocks spec §2): items is hard-enforced
            // (enforce_block_types) to only accept `image` child blocks — unlike the
            // picker-only allowlists elsewhere, a gallery of non-images makes no sense.
            ['slug' => 'gallery', 'label' => 'Gallery', 'icon' => 'i-lucide-images',
                'category' => 'Media',
                'description' => 'A responsive image grid with an optional lightbox.',
                'schema' => [
                    ['name' => 'items', 'type' => 'blocks',
                     'block_types' => ['image'], 'enforce_block_types' => true],
                    ['name' => 'columns', 'type' => 'enum', 'enum' => ['2', '3', '4']],
                    ['name' => 'aspect', 'type' => 'enum', 'enum' => ['natural', 'square', 'landscape']],
                    ['name' => 'lightbox', 'type' => 'boolean'],
                ]],
            ['slug' => 'logo', 'label' => 'Logo', 'icon' => 'i-lucide-badge-check',
                'category' => 'Media',
                'description' => 'The site logo (Settings → General); falls back to the site name.',
                'schema' => [
                    ['name' => 'size', 'type' => 'enum', 'enum' => ['small', 'medium', 'large']],
                    ['name' => 'link_home', 'type' => 'boolean'],
                ]],
            ['slug' => 'icon', 'label' => 'Icon', 'icon' => 'i-lucide-shapes',
                'category' => 'Media',
                'description' => 'A single decorative icon from the Lucide set, optionally linked.',
                'schema' => [
                    ['name' => 'icon', 'type' => 'string', 'required' => true,
                        'pattern' => '[a-z0-9]+(-[a-z0-9]+)*', 'format' => 'icon'],
                    ['name' => 'size', 'type' => 'enum', 'enum' => ['small', 'medium', 'large']],
                    ['name' => 'align', 'type' => 'enum', 'enum' => ['start', 'center', 'end']],
                    ['name' => 'url', 'type' => 'string'],
                    ['name' => 'label', 'type' => 'string'],
                ]],
            ['slug' => 'social_links', 'label' => 'Social links', 'icon' => 'i-lucide-share-2',
                'category' => 'Content',
                'description' => 'A row of brand icons linking to social profiles.',
                'schema' => [
                    ['name' => 'items', 'type' => 'blocks', 'block_types' => ['social_link']],
                ]],
            ['slug' => 'logos', 'label' => 'Logos', 'icon' => 'i-lucide-building-2',
                'category' => 'Media', 'description' => 'A “trusted by” strip of brand logos.',
                'schema' => [
                    ['name' => 'title', 'type' => 'string'],
                    ['name' => 'images', 'type' => 'asset', 'multiple' => true],
                    ['name' => 'grayscale', 'type' => 'boolean'],
                    ['name' => 'scroll', 'type' => 'boolean'],
                ]],
            ['slug' => 'video', 'label' => 'Video', 'icon' => 'i-lucide-video',
                'category' => 'Media',
                'description' => 'An uploaded video or a YouTube/Vimeo embed.',
                'schema' => [
                    ['name' => 'source', 'type' => 'enum', 'enum' => ['upload', 'embed']],
                    ['name' => 'video', 'type' => 'asset'],
                    ['name' => 'url', 'type' => 'string'],
                    ['name' => 'poster', 'type' => 'asset'],
                    ['name' => 'caption', 'type' => 'string'],
                    ['name' => 'width', 'type' => 'enum', 'enum' => ['normal', 'wide', 'full']],
                ]],
            ['slug' => 'audio', 'label' => 'Audio', 'icon' => 'i-lucide-audio-lines',
                'category' => 'Media', 'description' => 'An uploaded audio file with native controls.',
                'schema' => [
                    ['name' => 'audio', 'type' => 'asset', 'required' => true],
                    ['name' => 'title', 'type' => 'string'],
                ]],
            // A download link to any uploaded blob (not media-type-specific). `label`
            // is the link text (the resolver exposes no filename); falls back to
            // "Download". new_tab opens it in a new browser tab.
            ['slug' => 'file', 'label' => 'File', 'icon' => 'i-lucide-file',
                'category' => 'Media', 'description' => 'A download link to an uploaded file.',
                'schema' => [
                    ['name' => 'file', 'type' => 'asset', 'required' => true],
                    ['name' => 'label', 'type' => 'string'],
                    ['name' => 'new_tab', 'type' => 'boolean'],
                ]],

            // ---- Advanced ---------------------------------------------------
            ['slug' => 'html', 'label' => 'HTML', 'icon' => 'i-lucide-code',
                'category' => 'Advanced',
                'description' => 'Raw HTML, rendered verbatim. Trusted editors only — activate to opt in.',
                'active' => false,
                'schema' => [
                    ['name' => 'code', 'type' => 'text'],
                ]],
            ['slug' => 'shortcode', 'label' => 'Shortcode', 'icon' => 'i-lucide-braces',
                'category' => 'Advanced',
                'description' => 'Renders shortcodes/{name}.twig from the theme (or a DB template).',
                'schema' => [
                    ['name' => 'name', 'type' => 'string', 'required' => true,
                        'pattern' => '[a-z][a-z0-9_-]*'],
                    ['name' => 'params', 'type' => 'json'],
                ]],

            // ---- Items (children of collection blocks) ----------------------
            ['slug' => 'feature', 'label' => 'Feature', 'icon' => 'i-lucide-check',
                'category' => 'Items', 'description' => 'One feature: icon, title, description, link.',
                'schema' => [
                    ['name' => 'icon', 'type' => 'string', 'pattern' => '[a-z0-9]+(-[a-z0-9]+)*', 'format' => 'icon'],
                    ['name' => 'title', 'type' => 'string', 'required' => true],
                    ['name' => 'description', 'type' => 'text'],
                    ['name' => 'url', 'type' => 'string'],
                ]],
            ['slug' => 'accordion_item', 'label' => 'Accordion item', 'icon' => 'i-lucide-chevron-down',
                'category' => 'Items', 'description' => 'One question with a rich-text answer.',
                'schema' => [
                    ['name' => 'question', 'type' => 'string', 'required' => true],
                    ['name' => 'answer', 'type' => 'text', 'format' => 'rich'],
                ]],
            ['slug' => 'tab', 'label' => 'Tab', 'icon' => 'i-lucide-panel-top',
                'category' => 'Items', 'description' => 'One tab: label and panel blocks.',
                'schema' => [
                    ['name' => 'label', 'type' => 'string', 'required' => true],
                    ['name' => 'content', 'type' => 'blocks'],
                ]],
            ['slug' => 'stepper_item', 'label' => 'Stepper item', 'icon' => 'i-lucide-circle-dot',
                'category' => 'Items', 'description' => 'One numbered step: title and description.',
                'schema' => [
                    ['name' => 'title', 'type' => 'string', 'required' => true],
                    ['name' => 'description', 'type' => 'text'],
                ]],
            ['slug' => 'social_link', 'label' => 'Social link', 'icon' => 'i-lucide-link',
                'category' => 'Items', 'description' => 'One social profile: brand icon + URL.',
                'schema' => [
                    ['name' => 'icon', 'type' => 'string', 'required' => true,
                        'pattern' => 'brand:[a-z0-9]+(-[a-z0-9]+)*', 'format' => 'brand-icon'],
                    ['name' => 'url', 'type' => 'string', 'required' => true],
                    ['name' => 'label', 'type' => 'string'],
                ]],

            // ---- Pricing ----------------------------------------------------
            // Nuxt UI Pro pricing components (refs.md pricingPlan/pricingPlans/
            // pricingTable), translated to the theme's BEM convention. Flat CTA
            // fields + flat feature/section lists keep everything within
            // BlockDepth::MAX so they nest one wrapper deep.
            ['slug' => 'pricing_plan', 'label' => 'Pricing plan', 'icon' => 'i-lucide-badge-dollar-sign',
                'category' => 'Content',
                'description' => 'A single pricing plan card: price, features and a CTA.',
                'schema' => [
                    ['name' => 'title', 'type' => 'string'],
                    ['name' => 'description', 'type' => 'text'],
                    ['name' => 'price', 'type' => 'string'],
                    ['name' => 'discount', 'type' => 'string'],
                    ['name' => 'billing_period', 'type' => 'string'],
                    ['name' => 'billing_cycle', 'type' => 'string'],
                    ['name' => 'badge', 'type' => 'string'],
                    ['name' => 'features', 'type' => 'text'],
                    ['name' => 'feature_icon', 'type' => 'string',
                        'pattern' => '[a-z0-9]+(-[a-z0-9]+)*', 'format' => 'icon'],
                    ['name' => 'tagline', 'type' => 'string'],
                    ['name' => 'terms', 'type' => 'text'],
                    ['name' => 'button_label', 'type' => 'string'],
                    ['name' => 'button_url', 'type' => 'string'],
                    // Pricing-bridge spec §5.4: optional deep-link key to the admin billing
                    // checkout page. Render re-validates the SAME pattern independently
                    // (defense in depth) before ever consulting the soft-bound resolver.
                    ['name' => 'plan_key', 'type' => 'string', 'pattern' => '[a-z0-9._-]{1,100}'],
                    ['name' => 'button_variant', 'type' => 'enum', 'enum' => ['solid', 'outline']],
                    ['name' => 'variant', 'type' => 'enum', 'enum' => ['outline', 'solid', 'soft', 'subtle']],
                    ['name' => 'highlight', 'type' => 'boolean'],
                    ['name' => 'orientation', 'type' => 'enum', 'enum' => ['vertical', 'horizontal']],
                ]],
            ['slug' => 'pricing_plans', 'label' => 'Pricing plans', 'icon' => 'i-lucide-wallet-cards',
                'category' => 'Content',
                'description' => 'A row or stack of pricing plans, with an optional featured plan.',
                'schema' => [
                    ['name' => 'plans', 'type' => 'blocks', 'block_types' => ['pricing_plan']],
                    ['name' => 'orientation', 'type' => 'enum', 'enum' => ['horizontal', 'vertical']],
                    ['name' => 'compact', 'type' => 'boolean'],
                    ['name' => 'scale', 'type' => 'boolean'],
                ]],
            ['slug' => 'pricing_table', 'label' => 'Pricing table', 'icon' => 'i-lucide-table',
                'category' => 'Content',
                'description' => 'A feature-comparison table across pricing tiers.',
                'schema' => [
                    ['name' => 'tiers', 'type' => 'blocks', 'block_types' => ['pricing_tier']],
                    ['name' => 'features', 'type' => 'blocks', 'block_types' => ['pricing_feature']],
                    ['name' => 'highlight', 'type' => 'boolean'],
                ]],
            ['slug' => 'pricing_tier', 'label' => 'Pricing tier', 'icon' => 'i-lucide-columns-3',
                'category' => 'Items',
                'description' => 'One column of a pricing table: title, price and CTA.',
                'schema' => [
                    ['name' => 'title', 'type' => 'string'],
                    ['name' => 'description', 'type' => 'text'],
                    ['name' => 'price', 'type' => 'string'],
                    ['name' => 'discount', 'type' => 'string'],
                    ['name' => 'billing_period', 'type' => 'string'],
                    ['name' => 'billing_cycle', 'type' => 'string'],
                    ['name' => 'badge', 'type' => 'string'],
                    ['name' => 'button_label', 'type' => 'string'],
                    ['name' => 'button_url', 'type' => 'string'],
                    ['name' => 'button_variant', 'type' => 'enum', 'enum' => ['solid', 'outline']],
                    ['name' => 'highlight', 'type' => 'boolean'],
                ]],
            ['slug' => 'pricing_feature', 'label' => 'Pricing feature', 'icon' => 'i-lucide-list-checks',
                'category' => 'Items',
                'description' => 'One comparison row (or a section heading) with a value per tier.',
                'schema' => [
                    ['name' => 'is_section', 'type' => 'boolean'],
                    ['name' => 'title', 'type' => 'string'],
                    ['name' => 'value_1', 'type' => 'string'],
                    ['name' => 'value_2', 'type' => 'string'],
                    ['name' => 'value_3', 'type' => 'string'],
                    ['name' => 'value_4', 'type' => 'string'],
                ]],
            ['slug' => 'blog_posts', 'label' => 'Blog posts', 'icon' => 'i-lucide-newspaper',
                'category' => 'Content',
                'description' => 'Lists published posts as cards (dynamic).',
                'schema' => [
                    ['name' => 'type', 'type' => 'string', 'pattern' => '[a-z0-9]+(-[a-z0-9]+)*'],
                    ['name' => 'limit', 'type' => 'number', 'min' => 1, 'max' => 12],
                    ['name' => 'order', 'type' => 'enum', 'enum' => ['newest', 'oldest']],
                    ['name' => 'category', 'type' => 'string'],
                    ['name' => 'columns', 'type' => 'enum', 'enum' => ['1', '2', '3', '4']],
                    ['name' => 'variant', 'type' => 'enum',
                        'enum' => ['outline', 'soft', 'subtle', 'ghost', 'naked']],
                    ['name' => 'orientation', 'type' => 'enum', 'enum' => ['vertical', 'horizontal']],
                ]],
        ];
    }
}
