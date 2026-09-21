<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\Controllers;

use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Thallo\Core\Content\Docs\DocsSetup;
use Thallo\Core\Content\Http\DTOs\SetupDocsData;

/**
 * "Set up documentation", as a button: what `thallo:docs:setup` does, for a site whose owner has
 * the admin and not a shell. It makes the content type a docs section needs and lets the site
 * list it. Saying it again changes nothing, and a type that already exists is never rewritten:
 * what it lacks is reported.
 */
final class DocsSetupController
{
    public function __construct(private readonly DocsSetup $docs)
    {
    }

    #[ApiOperation(
        summary: 'Set up a documentation section',
        tags: ['Thallo Admin'],
        description: 'Creates the content type a docs section needs (title, summary, section, order, a '
            . 'plain-text Markdown body, source_path, edit_url) and adds it to the listing types, so '
            . '`/{type}` is an index and `/{type}/{page}` a page. Idempotent. Requires `content.manage`.',
    )]
    #[ApiResponse(201, description: 'The type was created.')]
    #[ApiResponse(200, description: 'The type already existed; `missing` names the fields it lacks.')]
    #[ApiResponse(422, description: 'The type or a section cannot be a URL segment.')]
    public function store(SetupDocsData $input): Response
    {
        $type = $input->type ?? 'docs';
        $sections = $input->sections ?? DocsSetup::DEFAULT_SECTIONS;
        $given = array_values(array_filter($sections, 'is_string'));
        try {
            if (count($given) !== count($sections)) {
                throw new \InvalidArgumentException('Every section is a name: lower-case, hyphenated.');
            }
            $result = $this->docs->run($type, $given);
        } catch (\InvalidArgumentException $e) {
            return Response::validation(['type' => $e->getMessage()]);
        }

        $data = ['type' => $type] + $result + ['url' => '/' . $type];
        return $result['created']
            ? Response::created($data, 'Documentation section created.')
            : Response::success($data, 'Documentation section already set up.');
    }
}
