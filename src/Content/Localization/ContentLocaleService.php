<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Localization;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\I18n\Contracts\LocaleManagerInterface;

final class ContentLocaleService
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly LocaleManagerInterface $locales,
    ) {
    }

    public function default(): string
    {
        return $this->locales->default();
    }

    /**
     * @return list<string>
     */
    public function enabled(): array
    {
        $codes = [];
        foreach ($this->locales->enabled() as $row) {
            if (is_array($row) && isset($row['code'])) {
                $codes[] = (string) $row['code'];
                continue;
            }
            if (is_string($row)) {
                $codes[] = $row;
            }
        }

        $codes = array_values(array_unique(array_filter($codes, static fn (string $code): bool => $code !== '')));
        return $codes === [] ? [$this->default()] : $codes;
    }

    public function isEnabled(string $locale): bool
    {
        return in_array($locale, $this->enabled(), true);
    }

    /**
     * @return array<string,list<string>>
     */
    public function validate(string $locale, string $field = 'locale'): array
    {
        if ($this->isEnabled($locale)) {
            return [];
        }

        return [$field => [sprintf('Locale "%s" is not enabled.', $locale)]];
    }

    /**
     * @return non-empty-list<string>
     */
    public function fallbackChain(string $locale): array
    {
        $chain = $this->locales->fallbackChain($locale);
        $chain = array_values(array_unique(array_filter($chain, static fn (string $code): bool => $code !== '')));
        return $chain === [] ? [$locale] : $chain;
    }
}
