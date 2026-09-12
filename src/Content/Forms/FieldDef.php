<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Forms;

/** Normalized form field — the single source of truth for render/validation/storage. */
final class FieldDef
{
    public const TYPES = ['text', 'email', 'tel', 'textarea', 'select', 'checkbox'];

    /** @param list<string> $options */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type,
        public readonly bool $required,
        public readonly ?string $placeholder,
        public readonly ?string $help,
        public readonly array $options,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['key' => $this->key, 'label' => $this->label, 'type' => $this->type,
            'required' => $this->required, 'placeholder' => $this->placeholder,
            'help' => $this->help, 'options' => $this->options];
    }

    /** @param array<string,mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            (string) $a['key'],
            (string) $a['label'],
            (string) $a['type'],
            (bool) ($a['required'] ?? false),
            isset($a['placeholder']) ? (string) $a['placeholder'] : null,
            isset($a['help']) ? (string) $a['help'] : null,
            array_values(array_map('strval', (array) ($a['options'] ?? []))),
        );
    }
}
