<?php

declare(strict_types=1);

namespace Thallo\Core\Support;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Renders extension README markdown to safe HTML for the admin.
 *
 * CommonMark is treated as a *configured-safe* renderer, NOT a general HTML sanitizer:
 *  - raw inline HTML is escaped (`html_input: escape`) — no <script>/<iframe> passthrough;
 *  - unsafe link schemes (javascript:, data:, vbscript:) lose their href (`allow_unsafe_links: false`);
 *  - external links get target=_blank rel="noopener noreferrer" (ExternalLinkExtension);
 *  - images are stripped — README badges/screenshots are remote, cosmetic, and add a needless
 *    privacy/security surface (they leak the viewer's IP to third parties on render).
 *
 * The output is therefore safe to hand to the SPA as trusted HTML.
 */
final class ReadmeRenderer
{
    private readonly MarkdownConverter $converter;

    public function __construct(string $internalHost = 'localhost')
    {
        $environment = new Environment([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'external_link' => [
                'internal_hosts' => $internalHost,
                'open_in_new_window' => true,
                'noopener' => 'external',
                'noreferrer' => 'external',
            ],
        ]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new ExternalLinkExtension());

        $this->converter = new MarkdownConverter($environment);
    }

    public function render(string $markdown): string
    {
        $html = $this->converter->convert($markdown)->getContent();

        // Strip images after rendering (CommonMark output is well-formed, so this is safe).
        return (string) preg_replace('/<img\b[^>]*>/i', '', $html);
    }
}
