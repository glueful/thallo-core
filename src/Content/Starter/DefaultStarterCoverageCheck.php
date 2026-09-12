<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Starter;

use Thallo\Tenancy\Contracts\StarterCoverageCheck;

final class DefaultStarterCoverageCheck implements StarterCoverageCheck
{
    public function __construct(
        private readonly StarterDefinitions $definitions,
        private readonly StarterProvenanceRepository $provenance,
    ) {
    }

    public function coverageViolations(): array
    {
        $violations = [];
        foreach ($this->provenance->divergentStates() as $row) {
            // Policy revision (2026-07-25, user decision — sp2c §6 note): `customized` no
            // longer blocks. A customized starter is a fully KNOWN state — provenance row
            // present, user-owned edits — and the row survives disable, so re-enablement
            // bookkeeping stays intact. Only genuinely incoherent states still block:
            // orphaned sources here, plus missing/provenance-less/dangling below.
            if (($row['state'] ?? null) === 'customized') {
                continue;
            }
            $violations[] = sprintf(
                '%s:%s is %s',
                $row['definition_kind'],
                $row['definition_key'],
                $row['state'],
            );
        }
        foreach ($this->definitions->syncKinds() as $kind) {
            foreach ($kind->definitions() as $definition) {
                $allowed = [$definition->definitionKey, ...$definition->adoptionKeys];
                $provenance = $this->provenance->findBySource($kind->kind(), $definition->sourceId);
                if ($provenance !== null) {
                    $key = (string) $provenance['definition_key'];
                    if (!in_array($key, $allowed, true) || $kind->locateExact($key) === null) {
                        $violations[] = $definition->sourceId . ' has dangling or wrong-key provenance';
                    }
                    continue;
                }
                $found = false;
                foreach ($allowed as $key) {
                    if ($kind->locateExact($key) !== null) {
                        $found = true;
                        break;
                    }
                }
                $violations[] = $definition->sourceId . ($found
                    ? ' has starter-shaped content without provenance'
                    : ' is missing; run thallo:tenant:sync');
            }
        }

        return array_values(array_unique($violations));
    }
}
