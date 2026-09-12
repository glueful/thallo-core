<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Starter;

final class SyncReport
{
    /** @var list<array{kind:string,source_id:string,action:string}> */
    private array $items = [];

    public function add(string $kind, string $sourceId, string $action): void
    {
        $this->items[] = compact('kind', 'sourceId', 'action');
    }

    /** @return list<array{kind:string,source_id:string,action:string}> */
    public function items(): array
    {
        return array_map(static fn(array $item): array => [
            'kind' => $item['kind'],
            'source_id' => $item['sourceId'],
            'action' => $item['action'],
        ], $this->items);
    }
}
