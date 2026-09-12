<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Sanitization;

use Thallo\Contracts\Content\RichHtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * The TipTap-scoped allowlist (sanitizer spec §2): built ADDITIVELY — an empty
 * config plus explicit allowElement()/allowAttribute() calls, never
 * allowSafeElements()-and-subtract (auditable, immune to upstream "safe" set
 * changes). Everything outside the vocabulary is STRIPPED, never rejected.
 * Fixed in code — not app-configurable (the TemplatePolicy stance).
 */
final class TipTapHtmlSanitizer implements RichHtmlSanitizer
{
    private readonly HtmlSanitizer $sanitizer;

    public function __construct()
    {
        $config = (new HtmlSanitizerConfig())
            // The engine's DEFAULT max input length silently truncates long
            // documents (spec §2 pinned gotcha) — set it explicitly.
            ->withMaxInputLength(1_000_000)
            ->allowLinkSchemes(['http', 'https', 'mailto'])
            ->allowRelativeLinks(true);

        foreach (
            ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'blockquote',
             'pre', 'code', 'strong', 'em', 's', 'u', 'a', 'br', 'hr'] as $element
        ) {
            $config = $config->allowElement($element);
        }
        $config = $config
            ->allowAttribute('href', ['a'])
            // TipTap task lists: <ul data-type="taskList"><li data-checked="…">.
            // Checkbox <input>s are NOT allowlisted — CSS renders state from data-checked.
            ->allowAttribute('data-type', ['ul'])
            ->allowAttribute('data-checked', ['li'])
            // allowRelativeLinks(true) treats network-path (//host) URLs as relative
            // and would preserve them — the safe_url posture forbids exactly that
            // (spec §2 pin). Runs AFTER the default URL sanitizer.
            ->withAttributeSanitizer(new BlockProtocolRelativeLinks());

        $this->sanitizer = new HtmlSanitizer($config);
    }

    public function sanitize(string $html): string
    {
        return $this->sanitizer->sanitize($html);
    }
}
