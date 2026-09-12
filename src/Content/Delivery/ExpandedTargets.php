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

    public function add(string $entryUuid, string $versionUuid): void
    {
        if ($entryUuid === '' || isset($this->byEntry[$entryUuid])) {
            return;
        }
        $this->byEntry[$entryUuid] = $versionUuid;
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
        sort($out);
        return $out;
    }
}
