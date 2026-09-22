<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Delivery;

/**
 * Collects the reference targets ACTUALLY spliced in during expansion (spec §4):
 * entry uuids feed Cache-Tag (purge reaches pages embedding the target); sorted
 * entry:version identities feed the delivery ETag (a republished target must
 * change the embedding response's validator — tags alone can't fix a false 304).
 * Unresolved targets are never recorded: tagging them would leak hidden entry
 * uuids through surrogate headers. INTERNAL metadata — never serialized into a
 * public body or template context.
 */
final class ExpandedTargets
{
    /** @var array<string,string> entry uuid => version uuid (first splice wins) */
    private array $byEntry = [];

    /** @var array<string,string> expanded asset uuid => fingerprint of what was described */
    private array $byAsset = [];

    public function add(string $entryUuid, string $versionUuid): void
    {
        if ($entryUuid === '' || isset($this->byEntry[$entryUuid])) {
            return;
        }
        $this->byEntry[$entryUuid] = $versionUuid;
    }

    /**
     * An expanded asset (AssetExpander): its fingerprint feeds the ETag only. Assets get no
     * Cache-Tag; nothing purges on a media-library edit, so the TTL bounds shared caches.
     */
    public function addAsset(string $assetUuid, string $fingerprint): void
    {
        $this->byAsset[$assetUuid] ??= $fingerprint;
    }

    /** @return list<string> deduped, insertion order */
    public function entryUuids(): array
    {
        return array_keys($this->byEntry);
    }

    /** @return list<string> SORTED "{entryUuid}:{versionUuid}" — stable ETag input */
    public function versionIdentities(): array
    {
        $out = [];
        foreach ($this->byEntry as $entry => $version) {
            $out[] = $entry . ':' . $version;
        }
        foreach ($this->byAsset as $asset => $fingerprint) {
            $out[] = 'asset:' . $asset . ':' . $fingerprint;
        }
        sort($out);
        return $out;
    }
}
