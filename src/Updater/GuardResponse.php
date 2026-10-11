<?php

declare(strict_types=1);

namespace Thallo\Core\Updater;

final class GuardResponse
{
    /** @param array<string,string> $headers */
    public function __construct(public int $status, public array $headers, public string $body)
    {
    }

    public static function json(int $status, array $data): self
    {
        $headers = ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'];
        return new self($status, $headers, json_encode($data, JSON_UNESCAPED_SLASHES) ?: '{}');
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->body;
    }
}
