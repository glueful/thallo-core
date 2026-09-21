<?php

declare(strict_types=1);

namespace Thallo\Core\Settings;

use Glueful\Bootstrap\ApplicationContext;
use Thallo\Contracts\Settings\AdminUrlProvider;

use function config;

/**
 * Where the admin is (the preview bar's Edit and Design links, the billing return).
 *
 * Thallo serves its own admin at /admin on the site's host, so a site that was told nothing
 * still knows: the address the visitor is on — or, with no request, the deploy's BASE_URL — plus
 * /admin. The setting (Settings › General, or RENDER_ADMIN_URL) is for an admin hosted elsewhere.
 *
 * The site's OWN address, given as the admin's, is never an admin: it is the site. The setup
 * screen saved exactly that until 1.0.0-beta.51, and every link built from it was a 404. It is
 * read as what was meant — the admin on this site.
 *
 * A site that turned the bundled admin off (`thallo.admin.enabled`) brings its own, somewhere
 * only it knows: nothing is guessed, and what it set is used as given.
 */
final class EngineAdminUrlProvider implements AdminUrlProvider
{
    private const MOUNT = '/admin';

    public function __construct(
        private readonly GeneralSettings $settings,
        private readonly ApplicationContext $context,
    ) {
    }

    public function adminUrl(?string $origin = null): ?string
    {
        return self::resolve(
            $this->settings->adminUrl(),
            $origin,
            (string) config($this->context, 'app.urls.base', ''),
            (bool) config($this->context, 'thallo.admin.enabled', true),
        );
    }

    public static function resolve(string $setting, ?string $origin, string $baseUrl, bool $bundled): ?string
    {
        $setting = rtrim(trim($setting), '/');
        $own = array_values(array_filter([self::origin($origin), self::origin($baseUrl)]));

        if ($setting !== '') {
            $given = self::origin($setting);
            return $bundled && $given !== null && in_array($given, $own, true) && self::isRoot($setting)
                ? $given . self::MOUNT
                : $setting;
        }
        return $bundled && $own !== [] ? $own[0] . self::MOUNT : null;
    }

    /** `scheme://host[:port]`, lower-cased, of an absolute http(s) URL; null for anything else. */
    private static function origin(?string $url): ?string
    {
        $parts = $url === null ? false : parse_url(trim($url));
        if (
            !is_array($parts)
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || ($parts['host'] ?? '') === ''
        ) {
            return null;
        }
        return strtolower($parts['scheme'] . '://' . $parts['host'])
            . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    private static function isRoot(string $url): bool
    {
        return in_array((string) (parse_url($url, PHP_URL_PATH) ?? ''), ['', '/'], true);
    }
}
