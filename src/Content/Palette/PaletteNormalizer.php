<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Palette;

use Thallo\Contracts\Style\Palette;
use Thallo\Core\Content\Schema\ContentTypeSchema;

/**
 * A document's colour tokens against the palette state a writer holds (custom palette spec §4.5):
 * a running replacement's source maps to its destination (its text colour to the contrast
 * destination, refused when the job has none); a fresh reference to an unconfigured slot is refused
 * unless a server-loaded trusted basis already holds it at that location. Always run on the ORIGINAL
 * payload — on a generation mismatch the fence runs it again from the same original (§4.3).
 */
final class PaletteNormalizer
{
    public function __construct(private readonly ColorTokenWalker $walker)
    {
    }

    /**
     * @param array<string,mixed> $doc
     * @param array<string,list<string>> $basis location => every token a trusted document holds there
     * @throws PaletteRefusal naming each refused location
     */
    public function normalize(
        string $kind,
        array $doc,
        PaletteSnapshot $snapshot,
        array $basis,
        ?ContentTypeSchema $schema = null,
    ): Normalized {
        $hadBrand = false;
        $errors = [];
        $rewrites = [];
        $out = $this->walker->map(
            $kind,
            $doc,
            static function (
                string $loc,
                string $token,
            ) use (
                $snapshot,
                $basis,
                &$hadBrand,
                &$errors,
                &$rewrites,
            ): ?string {
                $slot = Palette::slotOf($token);
                if ($slot === null) {
                    return null;
                }
                $hadBrand = true;
                $job = $snapshot->jobReplacing($slot);
                if ($job !== null) {
                    $to = Palette::isContrastToken($token) ? $job->contrastTo : $job->to;
                    if ($to === null) {
                        $errors[$loc] = "Text on Brand {$slot} has no replacement in the running replacement";
                        return null;
                    }
                    $rewrites[] = ['location' => $loc, 'from' => $token, 'to' => $to];
                    return $to;
                }
                if (!$snapshot->palette->isConfigured($slot) && !in_array($token, $basis[$loc] ?? [], true)) {
                    $errors[$loc] = "Brand {$slot} isn't in the palette";
                }
                return null;
            },
            $schema,
        );
        if ($errors !== []) {
            throw new PaletteRefusal($errors);
        }
        return new Normalized($out, $hadBrand, $this->walker->hasBrand($kind, $out, $schema), $rewrites);
    }

    /**
     * The trusted basis of some server-loaded documents: every token each holds, per location.
     *
     * @param array<string,mixed> ...$docs
     * @return array<string,list<string>>
     */
    public function basisOf(string $kind, ?ContentTypeSchema $schema, array ...$docs): array
    {
        $out = [];
        foreach ($docs as $doc) {
            foreach ($this->walker->tokens($kind, $doc, $schema) as $loc => $token) {
                if (!in_array($token, $out[$loc] ?? [], true)) {
                    $out[$loc][] = $token;
                }
            }
        }
        return $out;
    }

    /**
     * Several bases as one.
     *
     * @param array<string,list<string>> ...$bases
     * @return array<string,list<string>>
     */
    public function mergeBasis(array ...$bases): array
    {
        $out = [];
        foreach ($bases as $basis) {
            foreach ($basis as $loc => $tokens) {
                foreach ($tokens as $token) {
                    if (!in_array($token, $out[$loc] ?? [], true)) {
                        $out[$loc][] = $token;
                    }
                }
            }
        }
        return $out;
    }
}
