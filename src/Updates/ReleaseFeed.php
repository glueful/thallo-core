<?php

declare(strict_types=1);

namespace Thallo\Core\Updates;

/** Where published versions of a package are read from (Packagist in production, scripted in tests). */
interface ReleaseFeed
{
    /**
     * Every published version string of the package, newest first as the feed lists them.
     *
     * @return list<string>
     * @throws \RuntimeException on a transport failure or an unexpected document
     */
    public function versions(string $package): array;
}
