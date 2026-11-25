<?php

declare(strict_types=1);

namespace Thallo\Core\Content\DocsSync;

use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The docs sync's only outbound traffic (docs sync design §5). Anonymous HTTPS to two hosts fixed
 * here, never followed elsewhere: api.github.com for what a tag or branch is, codeload.github.com
 * for the archive, always by the commit it resolved to (a tag can move; a commit cannot). The repo,
 * tag, branch and SHA only ever fill path segments.
 */
final class GithubClient
{
    private const API = 'https://api.github.com';
    private const CODELOAD = 'https://codeload.github.com';
    private const MAX_TAG_STEPS = 5;

    private readonly HttpClientInterface $http;

    public function __construct(?HttpClientInterface $http = null)
    {
        $this->http = $http ?? HttpClient::create([
            'max_redirects' => 0,
            'headers' => ['User-Agent' => 'Thallo docs sync', 'Accept' => 'application/vnd.github+json'],
        ]);
    }

    /** @return array{private: bool, default_branch: string} */
    public function repo(string $repo): array
    {
        $data = $this->json(
            self::API . '/repos/' . self::repoPath($repo),
            "There is no public GitHub repository {$repo}.",
        );
        if (($data['private'] ?? true) !== false) {
            throw new GithubRefused(
                "{$repo} is not a public repository: the docs sync reads public repositories only.",
            );
        }
        return ['private' => false, 'default_branch' => (string) ($data['default_branch'] ?? 'main')];
    }

    /**
     * The highest version tag, by semver: names that are not versions are ignored, pre-releases
     * count only when asked. Tags, not GitHub Releases: a repo that pushes tags without Release
     * pages has its newest version only here.
     *
     * @return array{tag: string, sha: string}
     */
    public function latestRelease(string $repo, bool $prereleases): array
    {
        $refs = [];
        $url = self::API . '/repos/' . self::repoPath($repo) . '/git/matching-refs/tags/';
        for ($page = 0; $url !== null && $page < 50; $page++) {
            $response = $this->request('GET', $url);
            foreach ((array) $this->decode($response, "There is no public GitHub repository {$repo}.") as $ref) {
                if (is_array($ref) && is_string($ref['ref'] ?? null) && is_array($ref['object'] ?? null)) {
                    $refs[substr($ref['ref'], strlen('refs/tags/'))] = $ref['object'];
                }
            }
            $url = self::nextPage($response);
        }

        $parser = new VersionParser();
        $versions = [];
        foreach (array_keys($refs) as $tag) {
            try {
                $normalized = $parser->normalize((string) $tag);
            } catch (\UnexpectedValueException) {
                continue;
            }
            if (!$prereleases && VersionParser::parseStability($normalized) !== 'stable') {
                continue;
            }
            $versions[$normalized] = (string) $tag;
        }
        if ($versions === []) {
            throw new GithubRefused(
                "{$repo} has no version tags" . ($prereleases ? '' : ' that are not pre-releases') . '.',
            );
        }
        $tag = $versions[Semver::rsort(array_keys($versions))[0]];

        return ['tag' => $tag, 'sha' => $this->commitOf($repo, $refs[$tag])];
    }

    public function branchTip(string $repo, string $branch): string
    {
        $response = $this->request(
            'GET',
            self::API . '/repos/' . self::repoPath($repo) . '/commits/' . rawurlencode($branch),
            ['headers' => ['Accept' => 'application/vnd.github.sha']],
        );
        $this->check($response, "{$repo} has no branch {$branch}.");
        $sha = trim($response->getContent(false));
        if (preg_match('/\A[0-9a-f]{40}\z/', $sha) !== 1) {
            throw new GithubRefused("GitHub did not say which commit {$branch} is at.");
        }
        return $sha;
    }

    /**
     * Stream the archive of `$sha` to `$toPath`, refused past `$maxBytes` (counted as it arrives),
     * when shorter than GitHub said, or when it is not a zip. `$heartbeat` runs at least every
     * `$heartbeatEvery` seconds; false stops the download.
     *
     * @param callable(): bool $heartbeat
     */
    public function download(
        string $repo,
        string $sha,
        string $toPath,
        int $maxBytes,
        callable $heartbeat,
        int $heartbeatEvery = 30,
    ): void {
        if (preg_match('/\A[0-9a-f]{40}\z/', $sha) !== 1) {
            throw new \InvalidArgumentException('An archive is downloaded by its commit SHA.');
        }
        $url = self::CODELOAD . '/' . self::repoPath($repo) . '/zip/' . $sha;
        $out = fopen($toPath, 'wb');
        if ($out === false) {
            throw new \RuntimeException('The archive could not be written.');
        }
        $bytes = 0;
        $beat = time();
        try {
            $response = $this->request('GET', $url, ['timeout' => 60, 'max_duration' => 600]);
            foreach ($this->http->stream($response) as $chunk) {
                if ($chunk->isFirst()) {
                    $this->check($response, "GitHub has no archive of {$repo} at {$sha}.");
                }
                $data = $chunk->getContent();
                $bytes += strlen($data);
                if ($bytes > $maxBytes) {
                    throw new GithubRefused('The archive is larger than ' . intdiv($maxBytes, 1048576) . ' MB.');
                }
                fwrite($out, $data);
                if (time() - $beat >= $heartbeatEvery) {
                    if (!$heartbeat()) {
                        throw new GithubRefused('The download stopped: this sync no longer owns the run.');
                    }
                    $beat = time();
                }
            }
            $declared = $response->getHeaders(false)['content-length'][0] ?? null;
            if ($declared !== null && (int) $declared !== $bytes) {
                throw new GithubRefused('The archive download was cut short.', true);
            }
        } catch (ExceptionInterface $e) {
            throw new GithubRefused('GitHub could not be reached: ' . $e->getMessage(), true, $e);
        } finally {
            fclose($out);
        }
        $zip = new \ZipArchive();
        if ($zip->open($toPath, \ZipArchive::RDONLY) !== true) {
            throw new GithubRefused('The archive GitHub sent is not a complete zip file.', $declared === null);
        }
        $zip->close();
    }

    /** @param array<string,mixed> $object a ref's or a tag's `object` */
    private function commitOf(string $repo, array $object): string
    {
        for ($step = 0; $step <= self::MAX_TAG_STEPS; $step++) {
            $sha = (string) ($object['sha'] ?? '');
            if (($object['type'] ?? '') === 'commit') {
                return $sha;
            }
            if (($object['type'] ?? '') !== 'tag' || $step === self::MAX_TAG_STEPS) {
                break;
            }
            $tag = $this->json(
                self::API . '/repos/' . self::repoPath($repo) . '/git/tags/' . rawurlencode($sha),
                "GitHub has no tag object {$sha} in {$repo}.",
            );
            $object = (array) ($tag['object'] ?? []);
        }
        throw new GithubRefused('The tag does not lead to a commit within ' . self::MAX_TAG_STEPS . ' steps.');
    }

    /** @return array<mixed> */
    private function json(string $url, string $notFound): array
    {
        return (array) $this->decode($this->request('GET', $url), $notFound);
    }

    private function decode(ResponseInterface $response, string $notFound): mixed
    {
        $this->check($response, $notFound);
        try {
            return json_decode($response->getContent(false), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new GithubRefused('GitHub sent an answer that could not be read.');
        }
    }

    /** @param array<string,mixed> $options */
    private function request(string $method, string $url, array $options = []): ResponseInterface
    {
        try {
            return $this->http->request($method, $url, $options + ['timeout' => 8, 'max_redirects' => 0]);
        } catch (ExceptionInterface $e) {
            throw new GithubRefused('GitHub could not be reached: ' . $e->getMessage(), true, $e);
        }
    }

    private function check(ResponseInterface $response, string $notFound): void
    {
        try {
            $status = $response->getStatusCode();
            $headers = $response->getHeaders(false);
        } catch (ExceptionInterface $e) {
            throw new GithubRefused('GitHub could not be reached: ' . $e->getMessage(), true, $e);
        }
        if (in_array($status, [403, 429], true) && ($headers['x-ratelimit-remaining'][0] ?? null) === '0') {
            $reset = (int) ($headers['x-ratelimit-reset'][0] ?? time());
            throw new GithubRefused("GitHub's hourly limit is reached; try again after " . date('H:i', $reset) . '.');
        }
        if ($status >= 300 && $status < 400) {
            throw new GithubRefused('GitHub answered with a redirect, which the docs sync does not follow.');
        }
        if ($status === 404) {
            throw new GithubRefused($notFound);
        }
        if ($status >= 400) {
            throw new GithubRefused("GitHub refused the request (HTTP {$status}).");
        }
    }

    /** The next page's URL, only when it is on the API host. */
    private static function nextPage(ResponseInterface $response): ?string
    {
        $link = $response->getHeaders(false)['link'][0] ?? '';
        if (preg_match('/<([^>]+)>;\s*rel="next"/', $link, $m) !== 1) {
            return null;
        }
        return str_starts_with($m[1], self::API . '/') ? $m[1] : null;
    }

    private static function repoPath(string $repo): string
    {
        if (preg_match(DocsSyncSource::REPO_PATTERN, $repo) !== 1) {
            throw new \InvalidArgumentException("\"{$repo}\" is not a GitHub repository as owner/name.");
        }
        [$owner, $name] = explode('/', $repo, 2);
        return rawurlencode($owner) . '/' . rawurlencode($name);
    }
}
