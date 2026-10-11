<?php

declare(strict_types=1);

namespace Thallo\Core\Updater;

interface Host
{
    /** @param list<string> $argv @param array<string,string> $env */
    public function run(array $argv, string $cwd, array $env, int $timeout, callable $heartbeat): int;

    /**
     * @param list<string> $argv
     * @param array<string,string> $env
     * @return array{0:int,1:string} this command's own exit code and stdout
     */
    public function runCapture(array $argv, string $cwd, array $env, int $timeout, callable $heartbeat): array;

    /** @param list<string> $argv @return array{0:int,1:string} */
    public function capture(array $argv, string $cwd): array;

    /** @param array<string,string> $headers @return array{0:int,1:string} */
    public function http(string $url, array $headers, string $body): array;

    public function now(): int;

    public function sleep(int $seconds): void;
}
