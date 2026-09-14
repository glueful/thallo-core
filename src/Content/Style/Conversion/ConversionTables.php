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

    public static function shipped(): ConversionStages
    {
        return new ConversionStages(self::presentationGroup1());
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
        ]);
    }
}
