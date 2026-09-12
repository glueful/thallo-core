<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Seo;

final class ResolutionResult
{
    /**
     * @param array<string,mixed>|null $content
     * @param array<string,mixed>|null $redirect
     */
    private function __construct(
        private readonly string $kind,
        private readonly ?array $content = null,
        private readonly ?array $redirect = null
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function found(array $row): self
    {
        return new self('content', content: $row);
    }

    /** @param array<string,mixed> $descriptor */
    public static function moved(array $descriptor): self
    {
        return new self('redirect', redirect: $descriptor);
    }

    /** @param array<string,mixed> $descriptor */
    public static function gone(array $descriptor): self
    {
        return new self('gone', redirect: $descriptor);
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function isContent(): bool
    {
        return $this->kind === 'content';
    }

    public function isRedirect(): bool
    {
        return $this->kind === 'redirect';
    }

    public function isGone(): bool
    {
        return $this->kind === 'gone';
    }

    /** @return array<string,mixed> */
    public function content(): array
    {
        if ($this->content === null) {
            throw new \LogicException('Resolution result does not contain content.');
        }

        return $this->content;
    }

    /** @return array<string,mixed> */
    public function redirect(): array
    {
        if ($this->redirect === null) {
            throw new \LogicException('Resolution result does not contain a redirect descriptor.');
        }

        return $this->redirect;
    }
}
