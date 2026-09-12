<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Sanitization;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * Drops protocol-relative hrefs (sanitizer spec §2 pin): allowRelativeLinks(true)
 * treats network-path URLs (//evil.com) as relative and preserves them — the
 * safe_url posture forbids exactly that. Null drops the attribute; every other
 * value passes through unchanged. Runs after the default URL sanitizer.
 */
final class BlockProtocolRelativeLinks implements AttributeSanitizerInterface
{
    public function getSupportedElements(): ?array
    {
        return ['a'];
    }

    public function getSupportedAttributes(): ?array
    {
        return ['href'];
    }

    public function sanitizeAttribute(
        string $element,
        string $attribute,
        string $value,
        HtmlSanitizerConfig $config,
    ): ?string {
        return str_starts_with(ltrim($value), '//') ? null : $value;
    }
}
