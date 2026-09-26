<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Preview;

/**
 * A regions-stage session's records (regions-stage spec §4.2): the baseline holds both regions
 * `{blocks, settings, lock_version}` as minted, or as of this session's last save; the working copy
 * the accepted `{header, footer}` document. The records live in {@see SessionDocumentStore}.
 */
final class RegionPreviewStore extends SessionDocumentStore implements \Thallo\Contracts\Delivery\RegionStageSnapshots
{
    protected function namespace(): string
    {
        return 'regions';
    }

    /**
     * What one render shows, read once (regions-stage spec §4.4): the working copy if there is
     * one, else the baseline, else null (the session expired).
     *
     * @return array{source: 'working'|'baseline', regions: array<string,mixed>, epoch: ?string, revision: ?int}|null
     */
    public function snapshot(string $session): ?array
    {
        $working = $this->current($session);
        if ($working !== null) {
            return [
                'source' => 'working',
                'regions' => $working['fields'],
                'epoch' => $working['epoch'],
                'revision' => $working['revision'],
            ];
        }
        $baseline = $this->baseline($session);
        if ($baseline === null) {
            return null;
        }
        return ['source' => 'baseline', 'regions' => $baseline, 'epoch' => null, 'revision' => null];
    }
}
