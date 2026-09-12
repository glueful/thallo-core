<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Blocks\Migration;

/**
 * Restore blocker (block-migrations spec §5): the version being restored contains
 * an instance of a block type missing from the registry — hard-deleted, most
 * likely. No silent strip: the caller reports the type and the operator decides
 * (deactivate-over-delete keeps stored content valid; genuinely missing types are
 * corruption/operator-intervention cases).
 */
final class UnknownBlockTypeException extends \RuntimeException
{
    public function __construct(public readonly string $slug)
    {
        parent::__construct("unknown block type '{$slug}' (hard-deleted?) — cannot restore this version");
    }
}
