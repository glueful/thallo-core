<?php

declare(strict_types=1);

namespace Thallo\Core\Updates;

/** What the admin is told about updates. `available` is computed from `current` and `latest`. */
final readonly class UpdateStatus
{
    public function __construct(
        public ?string $current,
        public ?string $latest,
        public bool $available,
        public bool $development,
        public bool $enabled,
        public ?string $checkedAt,
        public string $notesUrl,
    ) {
    }

    /** @return array{current: ?string, latest: ?string, available: bool, development: bool, enabled: bool, checkedAt: ?string, notesUrl: string} */
    public function toArray(): array
    {
        return [
            'current' => $this->current,
            'latest' => $this->latest,
            'available' => $this->available,
            'development' => $this->development,
            'enabled' => $this->enabled,
            'checkedAt' => $this->checkedAt,
            'notesUrl' => $this->notesUrl,
        ];
    }
}
