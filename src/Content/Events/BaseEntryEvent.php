<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Events;

/**
 * Base for entry-lifecycle events (create/update/publish/unpublish/delete).
 *
 * Payload shape: entry uuid, content type, locale, version, actor, timestamp.
 * Never carries the entry's field values.
 */
abstract class BaseEntryEvent extends BaseContentEvent
{
    public function __construct(
        public readonly string $entry,
        public readonly string $type,
        public readonly ?string $locale = null,
        public readonly ?int $version = null,
        public readonly ?string $actor = null,
    ) {
        parent::__construct();
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'entry' => $this->entry,
            'type' => $this->type,
            'locale' => $this->locale,
            'version' => $this->version,
            'actor' => $this->actor,
            'timestamp' => $this->getTimestamp(),
        ];
    }

    /**
     * @return array{type:string,uuid:string,label:string}
     */
    public function auditTarget(): array
    {
        return ['type' => 'content_entry', 'uuid' => $this->entry, 'label' => $this->type];
    }
}
