<?php

declare(strict_types=1);

namespace Thallo\Core\Setup\Doctor;

/** One environment check's result. */
final class Check
{
    public const OK = 'ok';
    public const WARN = 'warning';
    public const FAIL = 'fail';

    public function __construct(
        public readonly string $name,
        public readonly string $status,
        public readonly string $message,
    ) {
    }

    public static function ok(string $name, string $message): self
    {
        return new self($name, self::OK, $message);
    }

    public static function warn(string $name, string $message): self
    {
        return new self($name, self::WARN, $message);
    }

    public static function fail(string $name, string $message): self
    {
        return new self($name, self::FAIL, $message);
    }
}
