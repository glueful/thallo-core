<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Layouts;

use Thallo\Core\Content\Repositories\ContentTypeRepository;

/**
 * What a layout's fields are called (sections and templates design §3.2, §4): each field of the
 * target's content type by its schema label, else its name made readable. One source for the
 * editor's session and for the labels a saved layout section keeps.
 */
final class LayoutFieldLabels
{
    public function __construct(private readonly ContentTypeRepository $types)
    {
    }

    /** @return array<string,string> field name => label; [] off a content type */
    public function forTarget(string $surface, string $target): array
    {
        $type = LayoutSaver::typeOf($surface, $target);
        $row = $type === null ? null : $this->types->findBySlug($type);
        $out = [];
        foreach ((array) ($row['schema'] ?? []) as $field) {
            if (is_array($field) && is_string($field['name'] ?? null)) {
                $label = $field['label'] ?? null;
                $out[$field['name']] = is_string($label) && $label !== '' ? $label : self::readable($field['name']);
            }
        }
        return $out;
    }

    /**
     * The labels of the fields a layout part shows — every field block's bound field — as the target
     * it comes from names them.
     *
     * @param list<array<string,mixed>> $blocks bound already ({@see LayoutValidator::fragment()})
     * @return array<string,string>
     */
    public function ofBlocks(string $surface, string $target, array $blocks): array
    {
        $labels = $this->forTarget($surface, $target);
        $out = [];
        $walk = static function (array $blocks) use (&$walk, &$out, $labels): void {
            foreach ($blocks as $block) {
                if (!is_array($block)) {
                    continue;
                }
                $field = $block['data']['field'] ?? null;
                $binds = isset(LayoutValidator::BINDINGS[(string) ($block['type'] ?? '')]);
                if ($binds && is_string($field) && $field !== '') {
                    $out[$field] = $labels[$field] ?? self::readable($field);
                }
                foreach ((array) ($block['data'] ?? []) as $value) {
                    if (is_array($value) && array_is_list($value) && isset($value[0]['type'])) {
                        $walk($value);
                    }
                }
            }
        };
        $walk($blocks);
        ksort($out);
        return $out;
    }

    public static function readable(string $name): string
    {
        return ucfirst(str_replace('_', ' ', $name));
    }
}
