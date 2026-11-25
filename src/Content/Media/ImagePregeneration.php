<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Media;

use Glueful\Bootstrap\ApplicationContext;

/**
 * Which resized copies of an uploaded image the queue worker makes at upload, so no visitor waits
 * for one (glueful/framework's GenerateBlobVariantsJob): every width the default theme's srcsets and
 * the media library's thumbnails ask for, in the image's own format and WebP.
 *
 * A site's config/uploads.php is its own file, copied at install, so a block written there would
 * reach new installs only; and the framework's own uploads.php ranks above extension defaults with
 * pre-generation off. So the block is applied as an override, unless the site's config/uploads.php
 * or its environment overlay sets `image_processing.pregenerate` itself, which then wins whole.
 * UPLOADS_PREGENERATE_VARIANTS=false turns it off; UPLOADS_PREGENERATE_QUEUE names the queue.
 */
final class ImagePregeneration
{
    /** Every width a template in the default theme, or the media library, puts in a URL. */
    private const WIDTHS = [160, 320, 480, 640, 768, 960, 1024, 1280, 1536, 1920];

    public static function apply(ApplicationContext $context): void
    {
        $siteConfig = (string) ($context->getConfigPaths()['application'] ?? $context->getBasePath() . '/config');
        if (self::siteSetsItsOwn($siteConfig, $context->getEnvironment())) {
            return;
        }
        try {
            $context->overrideConfig('uploads.image_processing.pregenerate', self::defaults());
        } catch (\LogicException) {
            // Overrides are boot-only. Applied after boot, copies are made on request instead: never
            // a reason to fail the app.
        }
    }

    /**
     * @return array{enabled: bool, queue: string, widths: list<int>, formats: list<string|null>,
     *     mime_types: list<string>}
     */
    public static function defaults(): array
    {
        return [
            'enabled' => (bool) env('UPLOADS_PREGENERATE_VARIANTS', true),
            'queue' => (string) env('UPLOADS_PREGENERATE_QUEUE', 'default'),
            'widths' => self::WIDTHS,
            // The image's own format for the <img> fallback and the library's thumbnails; WebP for
            // the <picture> source every current browser takes.
            'formats' => [null, 'webp'],
            'mime_types' => ['image/jpeg', 'image/png', 'image/webp'],
        ];
    }

    /** Whether the site's config/uploads.php, or its environment overlay, sets its own block. */
    public static function siteSetsItsOwn(string $configDir, string $environment): bool
    {
        $dir = rtrim($configDir, '/');
        foreach ([$dir . '/uploads.php', $dir . '/' . $environment . '/uploads.php'] as $file) {
            if (!is_file($file)) {
                continue;
            }
            try {
                $config = require $file;
            } catch (\Throwable) {
                continue;
            }
            $images = is_array($config) ? ($config['image_processing'] ?? null) : null;
            if (is_array($images) && array_key_exists('pregenerate', $images)) {
                return true;
            }
        }

        return false;
    }
}
