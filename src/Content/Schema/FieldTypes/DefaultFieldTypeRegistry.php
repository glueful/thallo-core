<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Schema\FieldTypes;

use Thallo\Contracts\Schema\FieldTypeDefinition;
use Thallo\Contracts\Schema\FieldTypeRegistry;

final class DefaultFieldTypeRegistry implements FieldTypeRegistry
{
    /** @var array<string,FieldTypeDefinition> */
    private array $types = [];

    public function register(FieldTypeDefinition $type): void
    {
        if (isset($this->types[$type->key()])) {
            throw new \InvalidArgumentException("Field type '{$type->key()}' is already registered.");
        }
        $this->types[$type->key()] = $type;
    }

    public function get(string $key): FieldTypeDefinition
    {
        return $this->types[$key] ?? throw new \OutOfBoundsException("Unknown field type '{$key}'.");
    }

    public function has(string $key): bool
    {
        return isset($this->types[$key]);
    }

    public function all(): array
    {
        return $this->types;
    }
}
