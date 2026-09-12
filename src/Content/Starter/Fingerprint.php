<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Starter;

final class Fingerprint
{
    /** @param array<string,mixed> $payload */
    public static function of(array $payload): string
    {
        return hash('sha256', (string) json_encode(
            self::canonicalize($payload),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }
        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }
        return $value;
    }
}
