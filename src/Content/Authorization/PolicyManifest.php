<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Authorization;

use Glueful\Bootstrap\ApplicationContext;

final class PolicyManifest
{
    public function __construct(private readonly CapabilityCatalog $catalog)
    {
    }

    /** @return array<string,mixed> */
    public function export(ApplicationContext $context): array
    {
        $payload = $this->catalog->payload($context);
        return ['manifest_version' => 1] + $payload + [
            'hash' => CapabilityCatalog::hashPayload($payload),
        ];
    }

    /** @param array<string,mixed> $manifest @return list<string> */
    public function validate(array $manifest): array
    {
        if (($manifest['manifest_version'] ?? null) !== 1) {
            return ['Unsupported policy manifest version.'];
        }
        $hash = $manifest['hash'] ?? null;
        if (!is_string($hash) || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
            return ['Policy manifest hash is invalid.'];
        }
        $payload = $manifest;
        unset($payload['manifest_version'], $payload['hash']);
        if (!hash_equals($hash, CapabilityCatalog::hashPayload($payload))) {
            return ['Policy manifest hash does not match its contents.'];
        }
        foreach (['algebra_version', 'reserved_roles', 'owner_floor', 'catalog', 'role_matrix'] as $key) {
            if (!array_key_exists($key, $payload)) {
                return ["Policy manifest is missing {$key}."];
            }
        }
        return [];
    }

    /** @param array<string,mixed> $old @param array<string,mixed> $new @return array<string,mixed> */
    public function compare(array $old, array $new): array
    {
        $errors = [...$this->validate($old), ...$this->validate($new)];
        if ($errors !== []) {
            throw new \InvalidArgumentException(implode(' ', $errors));
        }
        $roles = array_unique([
            ...array_keys((array) $old['role_matrix']),
            ...array_keys((array) $new['role_matrix']),
        ]);
        sort($roles);
        $diff = [];
        foreach ($roles as $role) {
            $before = array_values(array_filter((array) (($old['role_matrix'])[$role] ?? []), 'is_string'));
            $after = array_values(array_filter((array) (($new['role_matrix'])[$role] ?? []), 'is_string'));
            sort($before);
            sort($after);
            $diff[$role] = [
                'added' => array_values(array_diff($after, $before)),
                'removed' => array_values(array_diff($before, $after)),
            ];
        }
        return ['old_hash' => $old['hash'], 'new_hash' => $new['hash'], 'roles' => $diff];
    }
}
