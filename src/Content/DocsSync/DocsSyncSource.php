<?php

declare(strict_types=1);

namespace Thallo\Core\Content\DocsSync;

/**
 * Where a site's docs come from (docs sync design §4): a public GitHub repo, a folder in it, what
 * to follow, and the Markdown import's own options. Validated on the way in; a run keeps a copy.
 */
final class DocsSyncSource
{
    public const REPO_PATTERN = '/\A[A-Za-z0-9-]{1,39}\/[A-Za-z0-9._-]{1,100}\z/';

    /** @param list<string> $exclude */
    public function __construct(
        public readonly string $repo,
        public readonly string $folder,
        public readonly string $follow,
        public readonly ?string $branch,
        public readonly bool $prereleases,
        public readonly string $contentType,
        public readonly bool $publish,
        public readonly array $exclude,
        public readonly string $locale,
        public readonly string $editBranch,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     * @throws \InvalidArgumentException naming the field that is wrong
     */
    public static function fromArray(array $data): self
    {
        $repo = trim((string) ($data['repo'] ?? ''));
        if (
            preg_match(self::REPO_PATTERN, $repo) !== 1
            || str_contains($repo, '..')
            || explode('/', $repo)[1] === '.'
        ) {
            throw new \InvalidArgumentException('repo: give a GitHub repository as owner/name, e.g. glueful/thallo.');
        }
        $folder = (string) ($data['folder'] ?? '');
        $segments = $folder === '' ? [] : explode('/', $folder);
        if (str_starts_with($folder, '/') || in_array('..', $segments, true) || in_array('', $segments, true)) {
            throw new \InvalidArgumentException(
                'folder: give a folder inside the repository, e.g. docs, with no leading / and no ..',
            );
        }
        $follow = (string) ($data['follow'] ?? '');
        if (!in_array($follow, ['release', 'branch'], true)) {
            throw new \InvalidArgumentException('follow: choose the latest release or a branch.');
        }
        $branch = is_string($data['branch'] ?? null) ? trim($data['branch']) : '';
        if ($follow === 'branch' && ($branch === '' || str_contains($branch, '..'))) {
            throw new \InvalidArgumentException('branch: name the branch to follow.');
        }
        $contentType = trim((string) ($data['content_type'] ?? ''));
        if ($contentType === '') {
            throw new \InvalidArgumentException('content_type: choose the content type the pages belong to.');
        }
        $exclude = array_values(array_filter(array_map(
            static fn (mixed $e): string => is_string($e) ? trim($e, " /") : '',
            (array) ($data['exclude'] ?? []),
        )));
        $locale = trim((string) ($data['locale'] ?? 'en'));

        return new self(
            repo: $repo,
            folder: $folder,
            follow: $follow,
            branch: $follow === 'branch' ? $branch : null,
            prereleases: (bool) ($data['prereleases'] ?? true),
            contentType: $contentType,
            publish: (bool) ($data['publish'] ?? false),
            exclude: $exclude,
            locale: $locale !== '' ? $locale : 'en',
            editBranch: trim((string) ($data['edit_branch'] ?? ($follow === 'branch' ? $branch : ''))),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'repo' => $this->repo, 'folder' => $this->folder, 'follow' => $this->follow,
            'branch' => $this->branch, 'prereleases' => $this->prereleases,
            'content_type' => $this->contentType, 'publish' => $this->publish,
            'exclude' => $this->exclude, 'locale' => $this->locale, 'edit_branch' => $this->editBranch,
        ];
    }

    /** "Edit this page" links: https://github.com/{repo}/edit/{edit_branch}/{folder}, or null. */
    public function editBase(): ?string
    {
        if ($this->editBranch === '') {
            return null;
        }
        $base = 'https://github.com/' . $this->repo . '/edit/' . rawurlencode($this->editBranch);
        if ($this->folder === '') {
            return $base;
        }
        return $base . '/' . implode('/', array_map('rawurlencode', explode('/', $this->folder)));
    }
}
