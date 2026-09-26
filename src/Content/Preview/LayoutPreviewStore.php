<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Preview;

use Thallo\Contracts\Layouts\LayoutStageSnapshots;

/**
 * A layout editing session's records (type layouts spec §5.2–§5.5). The baseline is
 * `{layout: {blocks, settings}, lock_version, surface, target, sample}` — the saved layout, or the
 * starter when there is none — and the working copy the accepted `{blocks, settings}`.
 *
 * Removing the layout **retires** the session: the baseline becomes `{retired: true}` and the
 * working copy goes, under the lock every apply takes, so an apply either lands before the
 * retirement (and is deleted by it) or after it (and is refused) — never between.
 */
/** Not final: the save contract's proofs observe the order of its writes. */
class LayoutPreviewStore extends SessionDocumentStore implements LayoutStageSnapshots
{
    protected function namespace(): string
    {
        return 'layout';
    }

    public function retire(string $session, int $expiresAt): void
    {
        $this->locked($this->key('working', $session), fn () => $this->retireLocked($session, $expiresAt));
    }

    public function isRetired(string $session): bool
    {
        $value = $this->cache->get($this->key('baseline', $session));
        return is_array($value) && ($value['retired'] ?? false) === true && !$this->expired($value);
    }

    protected function refuses(string $session): bool
    {
        return $this->isRetired($session);
    }

    public function snapshot(string $session): ?array
    {
        if ($this->isRetired($session)) {
            return [
                'source' => 'baseline',
                'layout' => ['blocks' => [], 'settings' => []],
                'lock_version' => 0,
                'epoch' => null,
                'revision' => null,
                'retired' => true,
                'surface' => '',
                'target' => '',
                'sample' => null,
            ];
        }
        $baseline = $this->baseline($session);
        if ($baseline === null) {
            return null;
        }
        $working = $this->current($session);
        $layout = $working !== null ? $working['fields'] : ($baseline['layout'] ?? []);
        return [
            'source' => $working !== null ? 'working' : 'baseline',
            'layout' => [
                'blocks' => is_array($layout['blocks'] ?? null) ? array_values($layout['blocks']) : [],
                'settings' => is_array($layout['settings'] ?? null) ? $layout['settings'] : [],
            ],
            'lock_version' => (int) ($baseline['lock_version'] ?? 0),
            'epoch' => $working['epoch'] ?? null,
            'revision' => $working['revision'] ?? null,
            'retired' => false,
            'surface' => (string) ($baseline['surface'] ?? ''),
            'target' => (string) ($baseline['target'] ?? ''),
            'sample' => is_string($baseline['sample'] ?? null) ? $baseline['sample'] : null,
        ];
    }

    /** The retirement itself: the caller holds the working copy's lock. */
    private function retireLocked(string $session, int $expiresAt): void
    {
        $this->cache->set(
            $this->key('baseline', $session),
            ['retired' => true, 'exp' => $expiresAt],
            $this->ttl($expiresAt),
        );
        $this->cache->delete($this->key('working', $session));
    }
}
