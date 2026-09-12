<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Starter;

final class StarterDefinition
{
    /**
     * @param array<string,mixed> $payload
     * @param list<string> $adoptionKeys
     */
    public function __construct(
        public readonly string $sourceId,
        public readonly string $definitionKey,
        public readonly array $payload,
        public readonly array $adoptionKeys = [],
    ) {
    }
}
