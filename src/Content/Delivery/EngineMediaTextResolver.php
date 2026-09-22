<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Delivery;

use Glueful\Database\Connection;
use Thallo\Contracts\Delivery\MediaTextResolver;
use Thallo\Contracts\Delivery\MediaUrlResolver;

/**
 * A file's alt text and caption from `media_meta`, for a file the page may show: the URL resolver
 * decides servability, so a private or deleted file's words never reach a cached page. Answers are
 * kept for the life of the resolver, since a page can show one image several times.
 */
final class EngineMediaTextResolver implements MediaTextResolver
{
    private const EMPTY = ['alt' => '', 'caption' => ''];

    /** @var array<string, array{alt: string, caption: string}> */
    private array $memo = [];

    public function __construct(
        private readonly Connection $db,
        private readonly MediaUrlResolver $urls,
    ) {
    }

    public function texts(string $uuid): array
    {
        if (isset($this->memo[$uuid])) {
            return $this->memo[$uuid];
        }
        if ($this->urls->url($uuid) === null) {
            return $this->memo[$uuid] = self::EMPTY;
        }
        $row = $this->db->table('media_meta')->select(['alt_text', 'caption'])->where('blob_uuid', '=', $uuid)->first();

        return $this->memo[$uuid] = [
            'alt' => trim((string) ($row['alt_text'] ?? '')),
            'caption' => trim((string) ($row['caption'] ?? '')),
        ];
    }
}
