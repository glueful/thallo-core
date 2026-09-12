<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Events;

/**
 * Base for content-type ("model") events (create/update/delete).
 *
 * A model event describes a change to a content type/schema, not a single
 * entry — so locale and version are intentionally absent. Payload shape:
 * type (slug), actor, timestamp.
 */
abstract class BaseModelEvent extends BaseContentEvent
{
    public function __construct(
        public readonly string $type,
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
            'type' => $this->type,
            'actor' => $this->actor,
            'timestamp' => $this->getTimestamp(),
        ];
    }

    /**
     * A content type is identified by its slug — there is no uuid.
     *
     * @return array{type:string,uuid:string,label:string}
     */
    public function auditTarget(): array
    {
        return ['type' => 'content_type', 'uuid' => $this->type, 'label' => $this->type];
    }
}
