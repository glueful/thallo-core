<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Layouts;

use Glueful\Database\Connection;
use Thallo\Core\Content\Preview\LayoutPreviewStore;
use Thallo\Core\Content\Preview\LayoutPreviewToken;

/**
 * Save and remove (type layouts spec §5.5), the Regions save contract applied to one document.
 *
 * Inside the type's lock and then the layout's (a content-type migration takes the type's first):
 * validation that reads the database, the version comparison and the write. Everything that shows
 * the result — the session's new baseline, then clearing its working copy on the exact pair (save)
 * or retiring it (remove), then announcing the change ({@see LayoutChanges}) — is
 * registered with `afterCommit`, in that order: it runs once the outermost transaction commits, and
 * a rollback anywhere discards it. So a render at any moment shows the working copy or the
 * committed layout, never the old baseline, and a failed write changes nothing anyone can see.
 */
final class LayoutSaver
{
    public function __construct(
        private readonly Connection $db,
        private readonly LayoutWriteLock $lock,
        private readonly LayoutRepository $layouts,
        private readonly LayoutValidator $validator,
        private readonly LayoutPreviewStore $store,
        private readonly LayoutChanges $changes,
    ) {
    }

    /**
     * @param list<array<string,mixed>> $blocks
     * @param array<string,mixed> $settings
     * @param array{epoch: string, revision: int}|null $pair the working copy the save was made from
     * @return array{layout: array{blocks: list<array<string,mixed>>, settings: array<string,mixed>, lock_version: int},
     *     preview_cleared: bool} `preview_cleared` is what the effects did — false while an outer
     *     transaction still defers them
     * @throws LayoutVersionConflict
     * @throws \Thallo\Core\Content\Validation\ValidationException
     */
    public function save(
        LayoutPreviewToken $claims,
        array $blocks,
        array $settings,
        int $expected,
        ?array $pair,
        ?string $by,
    ): array {
        $surface = $claims->surface;
        $target = $claims->target;
        $cleared = false;
        $layout = $this->locked($surface, $target, function () use (
            $claims,
            $surface,
            $target,
            $blocks,
            $settings,
            $expected,
            $pair,
            $by,
            &$cleared,
        ): array {
            $stored = $this->layouts->find($surface, $target);
            // The version before anything else: an editor who is behind hears "changed" — also at a
            // target that is gone (an archive whose field was renamed away) — never a validation error.
            $current = $stored['lock_version'] ?? 0;
            if ($current !== $expected) {
                throw new LayoutVersionConflict($current);
            }
            $clean = $this->validator->validate($surface, $target, $blocks, $settings, $stored['blocks'] ?? []);
            $version = $this->layouts->saveExpected(
                $surface,
                $target,
                $clean['blocks'],
                $clean['settings'],
                $expected,
                $by,
            );
            $sample = $this->store->baseline($claims->session)['sample'] ?? $claims->sample;
            $effects = function () use ($claims, $surface, $target, $clean, $version, $sample, $pair, &$cleared): void {
                $this->store->putBaseline($claims->session, [
                    'layout' => $clean,
                    'lock_version' => $version,
                    'surface' => $surface,
                    'target' => $target,
                    'sample' => $sample,
                ], $claims->expiresAt);
                if ($pair !== null) {
                    $cleared = $this->store->clearIfPair($claims->session, $pair['epoch'], $pair['revision']);
                }
                $this->changes->announce($surface, $target);
            };
            $this->db->afterCommit($effects);
            return $clean + ['lock_version' => $version];
        });
        return ['layout' => $layout, 'preview_cleared' => $cleared];
    }

    /**
     * Tombstone the layout and retire the session that removed it.
     *
     * @return int the tombstone's version
     * @throws LayoutVersionConflict
     */
    public function remove(LayoutPreviewToken $claims, int $expected, ?string $by): int
    {
        $surface = $claims->surface;
        $target = $claims->target;
        return $this->locked($surface, $target, function () use ($claims, $surface, $target, $expected, $by): int {
            $current = $this->layouts->version($surface, $target);
            if ($current !== $expected || ($this->layouts->find($surface, $target)['blocks'] ?? null) === null) {
                throw new LayoutVersionConflict($current);
            }
            $version = $this->layouts->tombstone($surface, $target, $expected, $by);
            $this->db->afterCommit(function () use ($claims, $surface, $target): void {
                $this->store->retire($claims->session, $claims->expiresAt);
                $this->changes->announce($surface, $target);
            });
            return $version;
        });
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function locked(string $surface, string $target, callable $fn): mixed
    {
        $inner = fn (): mixed => $this->lock->within($surface, $target, $fn);
        $type = self::typeOf($surface, $target);
        return $type !== null ? $this->lock->withinType($type, $inner) : $inner();
    }

    /**
     * The content type a layout follows — whose schema migrations it waits on: an entry or listing
     * layout's target, an archive's target up to its `:`; null for a site-wide surface.
     */
    public static function typeOf(string $surface, string $target): ?string
    {
        return match ($surface) {
            'entry', 'listing' => $target,
            'archive' => explode(':', $target, 2)[0],
            default => null,
        };
    }
}
