<?php

declare(strict_types=1);

namespace Thallo\Core\Http\DTOs\Responses;

use Glueful\Http\Contracts\ResponseData;

/**
 * Doc-only shape of one API key as presented by
 * {@see \Thallo\Core\Http\Controllers\ApiKeyAdminController}. `key_prefix` is the public, non-secret part of
 * the key; the plaintext is never included here (only on create/rotate).
 *
 * @param list<string> $scopes
 * @param list<string> $allowed_ips
 */
final class ApiKeyData implements ResponseData
{
    public function __construct(
        public readonly string $uuid,
        public readonly string $name,
        public readonly string $key_prefix,
        public readonly string $owner_uuid,
        public readonly ?string $owner_label,
        public readonly ?string $tenant_uuid,
        public readonly ?string $tenant_name,
        public readonly array $scopes,
        public readonly array $allowed_ips,
        public readonly string $status,
        public readonly bool $is_rotated,
        public readonly ?string $expires_at,
        public readonly ?string $revoked_at,
        public readonly ?string $created_at,
    ) {
    }
}
