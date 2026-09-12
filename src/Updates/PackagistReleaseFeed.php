<?php

declare(strict_types=1);

namespace Thallo\Core\Updates;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Packagist's public package metadata (`repo.packagist.org/p2/<vendor>/<name>.json`). A plain
 * GET with no install identifier: nothing about the install leaves it. The document is
 * "minified": every entry after the first inherits the keys it omits from the entry before it,
 * so the reader carries the previous entry forward while collecting `version`.
 */
final class PackagistReleaseFeed implements ReleaseFeed
{
    private const BASE = 'https://repo.packagist.org/p2/';

    private readonly HttpClientInterface $http;

    public function __construct(?HttpClientInterface $http = null)
    {
        $this->http = $http ?? HttpClient::create(['timeout' => 8, 'max_redirects' => 2]);
    }

    public function versions(string $package): array
    {
        $document = $this->http->request('GET', self::BASE . $package . '.json')->toArray();

        $entries = $document['packages'][$package] ?? null;
        if (!is_array($entries)) {
            throw new \RuntimeException("Packagist metadata for {$package} has no package entries");
        }

        $versions = [];
        $previous = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $previous = array_merge($previous, $entry);
            $version = $previous['version'] ?? null;
            if (is_string($version) && $version !== '') {
                $versions[] = $version;
            }
        }

        return $versions;
    }
}
