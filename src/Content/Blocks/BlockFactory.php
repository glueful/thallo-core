<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Blocks;

/**
 * The server block factory (visual builder spec §5.5): the one place a fresh block's canonical
 * structure comes from. `data` carries every `blocks` field as an empty list and every `enum`
 * field as its first option — nothing else, so a field the editor never touched stays absent —
 * and `settings` starts empty. The block type row's `starter_content` travels separately: the
 * editor merges it over `data` and mints the ids for the block and every nested starter block.
 */
final class BlockFactory
{
    public function __construct(
        private readonly BlockTypeRepository $blockTypes,
    ) {
    }

    /**
     * @return array{
     *   block: array{type: string, data: array<string,mixed>, settings: array<string,mixed>},
     *   starter: array<string,mixed>,
     *   active: bool
     * }|null null for an unknown slug
     */
    public function make(string $slug): ?array
    {
        $row = $this->blockTypes->findBySlug($slug);
        if ($row === null) {
            return null;
        }
        $data = [];
        foreach ((array) $row['schema'] as $field) {
            if (!is_array($field) || !is_string($field['name'] ?? null)) {
                continue;
            }
            $type = (string) ($field['type'] ?? '');
            if ($type === 'blocks') {
                $data[$field['name']] = [];
            } elseif ($type === 'enum') {
                $options = array_values((array) ($field['enum'] ?? []));
                if ($options !== []) {
                    $data[$field['name']] = (string) $options[0];
                }
            }
        }
        $starter = $row['starter_content'] ?? null;

        return [
            'block' => ['type' => (string) $row['slug'], 'data' => $data, 'settings' => []],
            'starter' => is_array($starter) ? $starter : [],
            'active' => (bool) $row['active'],
        ];
    }
}
