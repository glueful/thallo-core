<?php

declare(strict_types=1);

namespace Thallo\Core\Setup;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\I18n\Services\LocaleManager;

/**
 * Makes the site's default language a real row in Settings › Languages. Where no language is the
 * default, the default is only the configured `i18n.default_locale` (`en`) as a fallback: the page
 * listed nothing, the first language added became the default in its place, and that code could
 * not be chosen again without adding it. Provision runs this on install and on every upgrade, and
 * it changes nothing once a language is the default.
 */
final class DefaultLanguage
{
    public function __construct(private readonly ApplicationContext $context)
    {
    }

    /** The code made the default language, or null when one already was (or i18n is off). */
    public function ensure(): ?string
    {
        $container = $this->context->getContainer();
        if (!$container->has(LocaleManager::class)) {
            return null;
        }
        /** @var LocaleManager $locales */
        $locales = $container->get(LocaleManager::class);
        foreach ($locales->all() as $row) {
            if ((bool) ($row['is_default'] ?? false)) {
                return null;
            }
        }
        $code = (string) \config($this->context, 'i18n.default_locale', 'en');
        if ($locales->find($code) === null) {
            $locales->create([
                'code' => $code,
                'name' => self::displayName($code, 'en'),
                'native_name' => self::displayName($code, $code),
            ]);
        }
        $locales->update($code, ['enabled' => true, 'is_default' => true]);
        return $code;
    }

    /** The language's name in a locale ("English", "Français"), or the code without intl. */
    private static function displayName(string $code, string $in): string
    {
        if (!class_exists(\Locale::class)) {
            return $code;
        }
        $name = \Locale::getDisplayLanguage($code, $in);
        return $name === '' || $name === $code ? $code : mb_convert_case($name, MB_CASE_TITLE);
    }
}
