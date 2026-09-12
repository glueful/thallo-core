<?php

declare(strict_types=1);

namespace Thallo\Core\Updates;

use Composer\Semver\Comparator;
use Composer\Semver\VersionParser;

/**
 * The newest published version an install may move to. Strictly greater than the installed
 * version under Composer's semver; a pre-release counts only when the install itself runs one
 * (a stable install is never pointed at a beta); branches (`dev-*`) and anything Composer
 * cannot parse never count. Null when nothing newer is published.
 */
final class LatestVersion
{
    /** @param list<string> $candidates version strings as Packagist publishes them */
    public static function pick(string $current, array $candidates): ?string
    {
        $parser = new VersionParser();
        try {
            $installed = $parser->normalize($current);
        } catch (\UnexpectedValueException) {
            return null;
        }
        $stableInstall = VersionParser::parseStability($installed) === 'stable';

        $best = null;
        $bestNormalized = null;
        foreach ($candidates as $candidate) {
            if (str_starts_with($candidate, 'dev-') || str_ends_with($candidate, '-dev')) {
                continue;
            }
            try {
                $normalized = $parser->normalize($candidate);
            } catch (\UnexpectedValueException) {
                continue;
            }
            if ($stableInstall && VersionParser::parseStability($normalized) !== 'stable') {
                continue;
            }
            if (!Comparator::greaterThan($normalized, $installed)) {
                continue;
            }
            if ($bestNormalized === null || Comparator::greaterThan($normalized, $bestNormalized)) {
                $best = ltrim($candidate, 'vV');
                $bestNormalized = $normalized;
            }
        }

        return $best;
    }
}
