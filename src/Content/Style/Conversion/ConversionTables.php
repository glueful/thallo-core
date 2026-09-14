<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Style\Conversion;

/**
 * The shipped conversion table (visual builder spec §7.2), one stage per release slice. Every
 * removed legacy field of a shipped block type has a row here: an explicit translation, a keep
 * (block semantics that stay in data), or unmappable with the domain a decision may pick from.
 */
final class ConversionTables
{
    public const GROUP_1 = 'presentation-group-1';
    public const GROUP_2 = 'presentation-group-2';

    public static function shipped(): ConversionStages
    {
        return new ConversionStages(self::presentationGroup1(), self::presentationGroup2());
    }

    /**
     * Container and style block (plan A5.1). Presets map to tokens outright; pixel boxes, raw
     * colours and the overlay colour need a decision; the class hook becomes the Advanced tab's
     * CSS classes.
     */
    public static function presentationGroup2(): ConversionStage
    {
        $choice = static fn (string $v): array => ['type' => 'choice', 'value' => $v];
        $token = static fn (string $v): array => ['type' => 'token', 'value' => $v];
        $shadow = [
            'none' => 'none', '2xs' => 'xs', 'xs' => 'xs', 'sm' => 'sm',
            'md' => 'md', 'lg' => 'lg', 'xl' => 'xl', '2xl' => 'xl',
        ];
        $shadowRule = static fn (mixed $v): ConversionOutcome => isset($shadow[$v])
            ? ConversionOutcome::setting('shadow', $token('shadow.' . $shadow[$v]))
            : ConversionOutcome::discard();
        $preset = static fn (array $map, string $path) => static fn (mixed $v): ConversionOutcome => isset($map[$v])
            ? ConversionOutcome::setting($path, $token($map[$v]))
            : ConversionOutcome::discard();
        $styleSpacing = [
            'none' => 'spacing.none', 'small' => 'spacing.md', 'medium' => 'spacing.lg', 'large' => 'spacing.xl',
        ];
        $containerSpacing = [
            'none' => 'spacing.none', 'small' => 'spacing.lg', 'medium' => 'spacing.2xl', 'large' => 'spacing.3xl',
        ];
        $notManaged = 'not a managed property; discard';
        $hex = 'a raw hex colour has no token; pick a colour token or discard';
        $box = 'a pixel box has no token; pick a spacing token for every side or discard';
        $discard = static fn (): ConversionOutcome => ConversionOutcome::discard();

        return new ConversionStage(self::GROUP_2, [
            // ---- container ----
            ConversionRule::unmappable('container', 'background_color', $hex, 'color', 'setting:colors.surface'),
            ConversionRule::convert('container', 'bg_repeat', $discard),
            ConversionRule::unmappable(
                'container',
                'overlay_color',
                'the overlay is light or dark; choose one (a `value` decision of light|dark) or discard',
                null,
                'data:overlay',
            ),
            ConversionRule::convert(
                'container',
                'overlay_opacity',
                static function (mixed $v): ConversionOutcome {
                    if (!is_numeric($v)) {
                        return ConversionOutcome::discard();
                    }
                    $pct = (float) $v;
                    return ConversionOutcome::data('overlay_opacity', $pct < 37.5 ? '25' : ($pct < 62.5 ? '50' : '75'));
                },
            ),
            ConversionRule::unmappable(
                'container',
                'max_width',
                'a pixel width has no token; pick a width token or discard',
                'width',
                'setting:width',
            ),
            ConversionRule::unmappable('container', 'min_height_px', "a pixel height is {$notManaged}"),
            ConversionRule::unmappable(
                'container',
                'gap',
                'a pixel gap has no token; pick a spacing token or discard',
                'spacing',
                'data:gap',
            ),
            ConversionRule::convert(
                'container',
                'padding_preset',
                $preset($containerSpacing, 'spacing.padding'),
            ),
            ConversionRule::unmappable('container', 'padding', $box, 'spacing', 'setting:spacing.padding'),
            ConversionRule::unmappable('container', 'margin', $box, 'spacing', 'setting:spacing.margin'),
            ConversionRule::unmappable(
                'container',
                'radius',
                'a pixel radius has no token; pick a radius token or discard',
                'radius',
                'setting:radius',
            ),
            ConversionRule::convert(
                'container',
                'border_style',
                static fn (mixed $v): ConversionOutcome => match ($v) {
                    'solid' => ConversionOutcome::setting('border.style', $choice('solid')),
                    'dashed', 'dotted' => ConversionOutcome::setting('border.style', $choice('dashed')),
                    default => ConversionOutcome::discard(),
                },
            ),
            ConversionRule::convert(
                'container',
                'border_width',
                static function (mixed $v) use ($choice): ConversionOutcome {
                    if (!is_numeric($v)) {
                        return ConversionOutcome::discard();
                    }
                    $px = (float) $v;
                    $width = $px <= 0 ? 'none' : ($px <= 1 ? 'thin' : 'thick');
                    return ConversionOutcome::setting('border.width', $choice($width));
                },
            ),
            ConversionRule::unmappable('container', 'border_color', $hex, 'color', 'setting:colors.border'),
            ConversionRule::convert('container', 'shadow', $shadowRule),
            // ---- style block ----
            ConversionRule::convert('style', 'padding', $preset($styleSpacing, 'spacing.padding')),
            ConversionRule::convert('style', 'margin', $preset($styleSpacing, 'spacing.margin')),
            ConversionRule::convert('style', 'shadow', $shadowRule),
            ConversionRule::unmappable('style', 'shadow_color', "a shadow colour is {$notManaged}"),
            ConversionRule::unmappable('style', 'shadow_opacity', "a shadow opacity is {$notManaged}"),
            ConversionRule::convert(
                'style',
                'class_hook',
                static function (mixed $v): ConversionOutcome {
                    // The same names the retired style_hook filter accepted, without its namespace.
                    $names = [];
                    foreach (is_string($v) ? (preg_split('/\s+/', trim($v)) ?: []) : [] as $name) {
                        if (str_starts_with($name, 'thallo-style-')) {
                            $name = substr($name, strlen('thallo-style-'));
                        }
                        if (preg_match('/^[A-Za-z_-][A-Za-z0-9_-]*$/', $name) === 1) {
                            $names[] = $name;
                        }
                    }
                    return $names === []
                        ? ConversionOutcome::discard()
                        : ConversionOutcome::advanced('css_classes', $names);
                },
            ),
        ], [
            'container' => [
                'background_color', 'bg_repeat', 'overlay_color', 'max_width', 'min_height_px', 'padding_preset',
                'padding', 'margin', 'radius', 'border_style', 'border_width', 'border_color', 'shadow',
            ],
            'style' => ['padding', 'margin', 'shadow', 'shadow_color', 'shadow_opacity', 'class_hook'],
        ]);
    }

    /** Heading, button, animated text, image and carousel (plan A4.6). */
    public static function presentationGroup1(): ConversionStage
    {
        $choice = static fn (string $v): array => ['type' => 'choice', 'value' => $v];
        $token = static fn (string $v): array => ['type' => 'token', 'value' => $v];
        $align = ['left' => 'start', 'start' => 'start', 'center' => 'center', 'right' => 'end', 'end' => 'end'];

        return new ConversionStage(self::GROUP_1, [
            ConversionRule::convert(
                'heading',
                'align',
                static fn (mixed $v): ConversionOutcome => isset($align[$v])
                    ? ConversionOutcome::setting('alignment.text', $choice($align[$v]))
                    : ConversionOutcome::discard(),
            ),
            ConversionRule::unmappable(
                'heading',
                'color',
                'a raw hex colour has no token; pick a colour token or discard',
                'color',
                'setting:colors.text',
            ),
            ConversionRule::convert(
                'button',
                'align',
                static fn (mixed $v): ConversionOutcome => isset($align[$v])
                    ? ConversionOutcome::setting('alignment.content', $choice($align[$v]))
                    : ConversionOutcome::discard(),
            ),
            ConversionRule::convert(
                'button',
                'shape',
                static function (mixed $v) use ($token): ConversionOutcome {
                    $radius = ['pill' => 'radius.full', 'rounded' => 'radius.md', 'square' => 'radius.none'];
                    return isset($radius[$v])
                        ? ConversionOutcome::setting('radius', $token($radius[$v]))
                        : ConversionOutcome::discard();
                },
            ),
            ConversionRule::keep('button', 'block'),
            ConversionRule::unmappable(
                'animated_text',
                'prefix_color',
                'a raw hex colour has no token; pick a colour token or discard',
                'color',
                'data:prefix_color',
            ),
            ConversionRule::unmappable(
                'animated_text',
                'rotate_color',
                'a raw hex colour has no token; pick a colour token or discard',
                'color',
                'data:rotate_color',
            ),
            ConversionRule::unmappable(
                'animated_text',
                'suffix_color',
                'a raw hex colour has no token; pick a colour token or discard',
                'color',
                'data:suffix_color',
            ),
            ConversionRule::convert(
                'image',
                'size',
                static function (mixed $v) use ($token): ConversionOutcome {
                    $width = ['normal' => 'width.content', 'wide' => 'width.container', 'full' => 'width.full'];
                    return isset($width[$v])
                        ? ConversionOutcome::setting('width', $token($width[$v]))
                        : ConversionOutcome::discard();
                },
            ),
            ConversionRule::unmappable(
                'image',
                'width',
                'a pixel width has no token; pick a width token or discard',
                'width',
                'setting:width',
            ),
            ConversionRule::unmappable(
                'image',
                'height',
                'a pixel height is not a managed property; discard',
            ),
            ConversionRule::convert(
                'carousel',
                'transition_duration',
                static function (mixed $v): ConversionOutcome {
                    if (!is_numeric($v)) {
                        return ConversionOutcome::discard();
                    }
                    $seconds = (float) $v;
                    $speed = $seconds < 0.8 ? 'fast' : ($seconds <= 1.6 ? 'normal' : 'slow');
                    return ConversionOutcome::data('speed', $speed);
                },
            ),
        ], [
            'heading' => ['align', 'color'],
            'button' => ['align', 'shape'],
            'image' => ['size', 'width', 'height'],
            'carousel' => ['transition_duration'],
        ]);
    }
}
