<?php

declare(strict_types=1);

namespace Thallo\Core\Capabilities\Declarations;

use Glueful\Bootstrap\ApplicationContext;
use Thallo\Contracts\Capability\ActivationCopy;
use Thallo\Contracts\Capability\Capability;
use Thallo\Contracts\Capability\ExternalFlowDestination;
use Thallo\Contracts\Capability\ManagementMode;

/**
 * Capabilities installed packages declare in their composer.json (`extra.thallo.capabilities`),
 * read from Composer's installed.json whether or not the package is enabled — the third-party path
 * (spec §7.4). The declaring package is the owning package. An entry that doesn't make a valid
 * capability is kept as an error, so it can be reported, never dropped silently.
 *
 *   "extra": { "thallo": { "capabilities": [ { "id": "acme.bookings", "label": "Bookings",
 *     "description": "…", "mode": "activation",
 *     "copy": { "turn_on": "…", "turn_off": "…", "links": [ { "label": "…", "to": "/…" } ] },
 *     "destination": { "path": "/…", "label": "…" } } ] } }
 */
final class PackageCapabilityDeclarations
{
    /** @var array<string, list<array{reason: string, package: string}>> why each declared entry is invalid */
    private array $errors = [];

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly ?string $installedJson = null,
    ) {
    }

    /** @return list<CapabilityDeclaration> */
    public function all(): array
    {
        $this->errors = [];
        $out = [];
        foreach ($this->packages() as $package) {
            $name = is_string($package['name'] ?? null) ? $package['name'] : null;
            $entries = $package['extra']['thallo']['capabilities'] ?? null;
            if ($name === null || !is_array($entries)) {
                continue;
            }
            foreach (array_values($entries) as $index => $entry) {
                $id = is_array($entry) && is_string($entry['id'] ?? null) && $entry['id'] !== ''
                    ? $entry['id']
                    : "{$name} (entry " . ($index + 1) . ')';
                try {
                    $out[] = new CapabilityDeclaration(self::capability($name, (array) $entry), 'package:' . $name);
                } catch (\Throwable $e) {
                    $this->errors[$id][] = [
                        'reason' => "an invalid declaration in {$name}: " . $e->getMessage(),
                        'package' => $name,
                    ];
                }
            }
        }
        return $out;
    }

    /**
     * Why declared entries aren't valid capabilities, and the packages that declared them, by id
     * (an entry without an id is keyed by its package and position). Every invalid entry is kept:
     * two packages with an invalid entry under one id are both listed.
     *
     * @return array<string, list<array{reason: string, package: string}>>
     */
    public function errorsById(): array
    {
        return $this->errors;
    }

    /** @param array<string, mixed> $entry */
    private static function capability(string $package, array $entry): Capability
    {
        $id = $entry['id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new \InvalidArgumentException('a declared capability needs an id');
        }
        $mode = ManagementMode::tryFrom(is_string($entry['mode'] ?? null) ? $entry['mode'] : 'simple')
            ?? throw new \InvalidArgumentException("unknown mode for {$id}");
        $copy = null;
        if (is_array($entry['copy'] ?? null)) {
            $links = [];
            foreach ((array) ($entry['copy']['links'] ?? []) as $link) {
                if (is_array($link) && is_string($link['label'] ?? null) && is_string($link['to'] ?? null)) {
                    $links[] = ['label' => $link['label'], 'to' => $link['to']];
                }
            }
            $copy = new ActivationCopy(
                is_string($entry['copy']['turn_on'] ?? null) ? $entry['copy']['turn_on'] : null,
                is_string($entry['copy']['turn_off'] ?? null) ? $entry['copy']['turn_off'] : null,
                $links,
            );
        }
        $destination = null;
        if (is_array($entry['destination'] ?? null)) {
            $destination = new ExternalFlowDestination(
                (string) ($entry['destination']['path'] ?? ''),
                (string) ($entry['destination']['label'] ?? ''),
            );
        }
        $requires = array_values(array_filter((array) ($entry['requires'] ?? []), 'is_string'));
        return new Capability(
            $id,
            $requires,
            is_string($entry['label'] ?? null) ? $entry['label'] : null,
            is_string($entry['description'] ?? null) ? $entry['description'] : null,
            $package,
            $mode,
            $destination,
            $copy,
        );
    }

    /** @return list<array<string, mixed>> */
    private function packages(): array
    {
        $path = $this->installedJson ?? base_path($this->context, 'vendor/composer/installed.json');
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            return [];
        }
        $packages = is_array($data['packages'] ?? null) ? $data['packages'] : $data;
        return array_values(array_filter($packages, 'is_array'));
    }
}
