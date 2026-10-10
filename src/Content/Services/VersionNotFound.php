<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Services;

/** A version id that is not one of this entry's retained versions in this locale. */
final class VersionNotFound extends \RuntimeException
{
    public function __construct(public readonly string $versionUuid)
    {
        parent::__construct("version {$versionUuid} is not this entry's");
    }
}
