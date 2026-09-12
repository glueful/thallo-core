<?php

declare(strict_types=1);

namespace Thallo\Core\Http\Controllers;

use Thallo\Core\Http\DTOs\ApiKeyListQuery;
use Thallo\Core\Http\DTOs\CreateApiKeyData;
use Thallo\Core\Http\DTOs\ErrorResponse;
use Thallo\Core\Http\DTOs\Responses\ApiKeyCreatedData;
use Thallo\Core\Http\DTOs\Responses\ApiKeyListData;
use Thallo\Core\Http\DTOs\Responses\ApiKeyResultData;
use Thallo\Core\Http\DTOs\Responses\ApiKeyRotatedData;
use Thallo\Core\Http\DTOs\RotateApiKeyData;
use Thallo\Core\Http\DTOs\UpdateApiKeyScopesData;
use Thallo\Core\Http\DTOs\UpdateApiKeyTenantData;
use Glueful\Auth\ApiKey\ApiKey;
use Glueful\Auth\ApiKey\ApiKeyService;
use Thallo\Core\Support\ActorHelper;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Http\Response;
use Glueful\Permissions\PermissionManager;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Tenancy\ApiKeyBinding\TenantApiKeyBindingRepository;

/**
 * Admin API for the framework `api_keys` store (programmatic, non-session access).
 *
 * The framework ships {@see ApiKeyService} (create/rotate/revoke/verify) and a CLI, but no HTTP
 * surface; this is the system-wide console the SPA's Developers › API Keys page drives. It lists
 * EVERY key with its owner, so any admin holding `system.access` can audit and revoke keys across
 * the instance. New keys are always minted for the calling admin — issuing on another user's behalf
 * is intentionally out of scope (and would need its own policy).
 *
 * The plaintext key is shown exactly once (on create/rotate); only its SHA-256 hash is stored, so a
 * lost key can only be rotated or revoked, never re-read. Gated by `system.access` — see
 * routes/admin.php.
 */
final class ApiKeyAdminController
{
    private const PER_PAGE_MAX = 100;
    private const GRACE_HOURS_DEFAULT = 24;
    private const GRACE_HOURS_MAX = 720; // 30 days

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly Connection $connection,
        private readonly TenantApiKeyBindingRepository $bindings,
    ) {
    }

    /** GET /v1/admin/api-keys — every key, newest first; optional status + name filters. */
    #[ApiOperation(
        summary: 'List API keys',
        description: 'System-wide, paginated list of API keys with their owner and status. Optional '
            . '`status` (active|expired|revoked), `q` (name search), `page`, `per_page`. Requires the '
            . '`system.access` permission.',
        tags: ['API Keys'],
    )]
    #[ApiResponse(200, schema: ApiKeyListData::class, description: 'API key page.')]
    #[ApiResponse(422, schema: ErrorResponse::class, envelope: false, description: 'Invalid query params.')]
    public function index(ApiKeyListQuery $query): Response
    {
        $page = max(1, $query->page ?? 1);
        $perPage = min(self::PER_PAGE_MAX, max(1, $query->per_page ?? 30));

        $builder = db($this->context)->table('api_keys')->orderBy('created_at', 'desc');
        $this->applyStatusFilter($builder, $query->status ?? '');

        $search = trim((string) ($query->q ?? ''));
        if ($search !== '') {
            $builder->where('name', 'LIKE', '%' . $search . '%');
        }

        /** @var array{data:array<int,array<string,mixed>>,total:int,current_page:int,per_page:int} $result */
        $result = $builder->paginate($page, $perPage);
        $rows = array_values($result['data']);
        $owners = $this->ownerLabels($rows);

        return Response::success([
            'api_keys' => array_map(fn (array $r): array => $this->present($r, $owners), $rows),
            'total' => $result['total'],
            'current_page' => $result['current_page'],
            'per_page' => $result['per_page'],
        ], 'API keys retrieved.');
    }

    /** GET /v1/admin/api-keys/{uuid} — one key (never includes the secret). */
    #[ApiOperation(summary: 'Get an API key', tags: ['API Keys'])]
    #[ApiResponse(200, schema: ApiKeyResultData::class, description: 'API key.')]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'No such key.')]
    public function show(string $uuid): Response
    {
        $row = $this->rowFor($uuid);
        if ($row === null) {
            return Response::notFound('API key not found.');
        }

        return Response::success(
            ['api_key' => $this->present($row, $this->ownerLabels([$row]))],
            'API key retrieved.',
        );
    }

    /** POST /v1/admin/api-keys — mint a key for the calling admin; returns the plaintext ONCE. */
    #[ApiOperation(
        summary: 'Create an API key',
        description: 'Mints a new key owned by the authenticated admin. The plaintext key is returned '
            . 'once as `plain` and never stored. Requires `system.access`.',
        tags: ['API Keys'],
    )]
    #[ApiResponse(201, schema: ApiKeyCreatedData::class, description: 'Created key + one-time plaintext.')]
    #[ApiResponse(422, schema: ErrorResponse::class, envelope: false, description: 'Validation failed.')]
    public function store(CreateApiKeyData $input, Request $request): Response
    {
        $name = trim($input->name);
        if ($name === '') {
            return Response::validation(['name' => 'A name is required.']);
        }

        $userUuid = $this->currentUserUuid($request);
        if ($userUuid === null) {
            return Response::error('Could not resolve the current user.', 401);
        }

        $expiresAt = $this->expiryField($input->expires_at);
        if ($expiresAt === false) {
            return Response::validation(['expires_at' => 'Could not understand that expiry date.']);
        }

        $scopes = $this->cleanList($input->scopes);
        $allowedIps = $this->cleanList($input->allowed_ips);

        $tenantUuid = $this->normalizeTenant($input->tenant_uuid);
        if ($tenantUuid !== null && !$this->canManageTenancy($userUuid)) {
            return Response::forbidden('The tenancy.manage permission is required to bind an API key.');
        }
        if ($tenantUuid !== null && !$this->tenantExists($tenantUuid)) {
            return Response::validation(['tenant_uuid' => 'Workspace not found.']);
        }

        $created = $this->connection->transaction(function () use (
            $userUuid,
            $name,
            $scopes,
            $allowedIps,
            $expiresAt,
            $tenantUuid,
        ): array {
            $created = ApiKeyService::create($this->context, [
                'user_uuid' => $userUuid,
                'name' => $name,
                'scopes' => $scopes !== [] ? $scopes : null,
                'allowed_ips' => $allowedIps !== [] ? $allowedIps : null,
                'expires_at' => $expiresAt,
            ]);
            if ($tenantUuid !== null) {
                $this->bindings->bind((string) $created['key']->uuid, $tenantUuid);
            }
            return $created;
        });

        $row = $this->rowFor($created['key']->uuid);

        return Response::created([
            'api_key' => $row !== null ? $this->present($row, $this->ownerLabels([$row])) : null,
            'plain' => $created['plain'],
        ], 'API key created.');
    }

    /** POST /v1/admin/api-keys/{uuid}/rotate — issue a successor; old key expiry bounded by a grace window. */
    #[ApiOperation(
        summary: 'Rotate an API key',
        description: 'Issues a new key inheriting the scopes/IPs/expiry of the old one, and sets the '
            . 'old key to expire at the grace deadline (`grace_hours`, default 24, max 720) or at its '
            . 'own original expiry, whichever is EARLIER — rotation never extends a superseded key '
            . '(framework 1.71.0). Both keys work until the applied `old_expires_at`. Returns the new '
            . 'plaintext once. Requires `system.access`.',
        tags: ['API Keys'],
    )]
    #[ApiResponse(200, schema: ApiKeyRotatedData::class, description: 'New key + one-time plaintext + old key expiry.')]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'No such key.')]
    #[ApiResponse(409, schema: ErrorResponse::class, envelope: false, description: 'Key is revoked.')]
    public function rotate(RotateApiKeyData $input, string $uuid): Response
    {
        $key = $this->findKey($uuid);
        if ($key === null) {
            return Response::notFound('API key not found.');
        }
        if ($key->isRevoked()) {
            return Response::error('A revoked key cannot be rotated.', 409);
        }

        $grace = (int) ($input->grace_hours ?? self::GRACE_HOURS_DEFAULT);
        $grace = min(self::GRACE_HOURS_MAX, max(1, $grace));

        [$result, $newRow] = $this->connection->transaction(function () use ($key, $grace): array {
            $result = ApiKeyService::rotate($this->context, $key, $grace);
            // Exact successor lookup via the framework's `new_uuid` (1.71.0) —
            // the previous rotated_from_id + created_at DESC heuristic was
            // ambiguous for two rotations within the same second.
            $newRow = db($this->context)->table('api_keys')
                ->where('uuid', '=', (string) $result['new_uuid'])
                ->first();
            if (is_array($newRow)) {
                $this->bindings->copyBinding((string) $key->uuid, (string) $newRow['uuid']);
            }
            return [$result, $newRow];
        });

        return Response::success([
            'api_key' => is_array($newRow) ? $this->present($newRow, $this->ownerLabels([$newRow])) : null,
            'plain' => $result['new_plain'],
            'old_expires_at' => $result['old_expires_at'],
        ], 'API key rotated.');
    }

    /** PATCH /v1/admin/api-keys/{uuid}/scopes — replace a key's scopes wholesale (no key rotation). */
    #[ApiOperation(
        summary: 'Replace an API key’s scopes',
        description: 'Overwrites the key’s scope list in place — the key value is unchanged. Used by '
            . 'the collections admin to grant/revoke per-collection access. Requires `system.access`.',
        tags: ['API Keys'],
    )]
    #[ApiResponse(200, schema: ApiKeyResultData::class, description: 'Updated key.')]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'No such key.')]
    public function updateScopes(UpdateApiKeyScopesData $input, string $uuid): Response
    {
        $row = $this->rowFor($uuid);
        if ($row === null) {
            return Response::notFound('API key not found.');
        }

        $scopes = $this->cleanList($input->scopes);
        db($this->context)->table('api_keys')->where('uuid', $uuid)->update([
            'scopes' => $scopes !== [] ? json_encode(array_values($scopes)) : null,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $updated = $this->rowFor($uuid) ?? $row;

        return Response::success(
            ['api_key' => $this->present($updated, $this->ownerLabels([$updated]))],
            'API key scopes updated.',
        );
    }

    /** PATCH /v1/admin/api-keys/{uuid}/tenant — bind or unbind the key's workspace authority. */
    #[ApiOperation(
        summary: 'Update an API key workspace binding',
        description: 'Binds the key to one workspace, or unbinds it with a null tenant_uuid. '
            . 'Requires system.access and tenancy.manage.',
        tags: ['API Keys'],
    )]
    #[ApiResponse(200, schema: ApiKeyResultData::class, description: 'Updated key.')]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'No such key.')]
    #[ApiResponse(422, schema: ErrorResponse::class, envelope: false, description: 'Workspace not found.')]
    public function updateTenant(UpdateApiKeyTenantData $input, string $uuid): Response
    {
        $row = $this->rowFor($uuid);
        if ($row === null) {
            return Response::notFound('API key not found.');
        }
        $tenantUuid = $this->normalizeTenant($input->tenant_uuid);
        if ($tenantUuid !== null && !$this->tenantExists($tenantUuid)) {
            return Response::validation(['tenant_uuid' => 'Workspace not found.']);
        }

        $this->connection->transaction(function () use ($uuid, $tenantUuid): void {
            if ($tenantUuid === null) {
                $this->bindings->unbind($uuid);
            } else {
                $this->bindings->bind($uuid, $tenantUuid);
            }
        });

        $updated = $this->rowFor($uuid) ?? $row;
        return Response::success(
            ['api_key' => $this->present($updated, $this->ownerLabels([$updated]))],
            'API key workspace binding updated.',
        );
    }

    /** DELETE /v1/admin/api-keys/{uuid} — revoke immediately (soft; the row is kept for audit). */
    #[ApiOperation(
        summary: 'Revoke an API key',
        description: 'Marks the key revoked so it stops authenticating immediately. The row is kept '
            . '(revoked_at is set) for audit. Requires `system.access`.',
        tags: ['API Keys'],
    )]
    #[ApiResponse(200, description: 'Revoked.')]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'No such key.')]
    public function destroy(string $uuid): Response
    {
        $key = $this->findKey($uuid);
        if ($key === null) {
            return Response::notFound('API key not found.');
        }

        $this->connection->transaction(function () use ($key): void {
            ApiKeyService::revoke($this->context, $key);
            $this->bindings->unbind((string) $key->uuid);
        });

        return Response::success(['revoked' => true], 'API key revoked.');
    }

    // ---- Helpers --------------------------------------------------------------

    private function applyStatusFilter(object $query, string $status): void
    {
        $now = date('Y-m-d H:i:s');
        match ($status) {
            'revoked' => $query->whereNotNull('revoked_at'),
            'expired' => $query->whereNull('revoked_at')
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', $now),
            'active' => $query->whereNull('revoked_at')
                ->whereRaw('(expires_at IS NULL OR expires_at >= ?)', [$now]),
            default => null,
        };
    }

    private function findKey(string $uuid): ?ApiKey
    {
        $key = ApiKey::query($this->context)->where('uuid', '=', $uuid)->first();

        return $key instanceof ApiKey ? $key : null;
    }

    /** @return array<string,mixed>|null */
    private function rowFor(string $uuid): ?array
    {
        $row = db($this->context)->table('api_keys')->where('uuid', '=', $uuid)->first();

        return is_array($row) ? $row : null;
    }

    /**
     * Resolve owner uuids to a username/email label in one query.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,array{username:?string,email:?string}>
     */
    private function ownerLabels(array $rows): array
    {
        $uuids = array_values(array_unique(array_filter(array_map(
            static fn (array $r): string => (string) ($r['user_uuid'] ?? ''),
            $rows,
        ))));
        if ($uuids === []) {
            return [];
        }

        $map = [];
        foreach (db($this->context)->table('users')->whereIn('uuid', $uuids)->get() as $u) {
            $map[(string) ($u['uuid'] ?? '')] = [
                'username' => is_string($u['username'] ?? null) ? $u['username'] : null,
                'email' => is_string($u['email'] ?? null) ? $u['email'] : null,
            ];
        }

        return $map;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,array{username:?string,email:?string}> $owners
     * @return array<string,mixed>
     */
    private function present(array $row, array $owners): array
    {
        $ownerUuid = (string) ($row['user_uuid'] ?? '');
        $owner = $owners[$ownerUuid] ?? null;
        $tenantUuid = $this->bindings->tenantFor((string) ($row['uuid'] ?? ''));
        $tenant = $tenantUuid !== null
            ? $this->connection->table('tenants')->where('uuid', $tenantUuid)->first()
            : null;

        return [
            'uuid' => (string) ($row['uuid'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            // key_prefix is the public, non-secret part (env tag + 8 random chars); safe to display.
            'key_prefix' => (string) ($row['key_prefix'] ?? ''),
            'owner_uuid' => $ownerUuid,
            'owner_label' => $owner['username'] ?? $owner['email'] ?? ($ownerUuid !== '' ? $ownerUuid : null),
            'tenant_uuid' => $tenantUuid,
            'tenant_name' => is_array($tenant) ? ($tenant['name'] ?? null) : null,
            'scopes' => $this->decodeList($row['scopes'] ?? null),
            'allowed_ips' => $this->decodeList($row['allowed_ips'] ?? null),
            'status' => $this->status($row),
            'is_rotated' => ($row['rotated_from_id'] ?? null) !== null,
            'expires_at' => $row['expires_at'] ?? null,
            'revoked_at' => $row['revoked_at'] ?? null,
            'created_at' => $row['created_at'] ?? null,
        ];
    }

    /** @param array<string,mixed> $row */
    private function status(array $row): string
    {
        if (($row['revoked_at'] ?? null) !== null) {
            return 'revoked';
        }
        $expires = $row['expires_at'] ?? null;
        if (is_string($expires) && $expires !== '') {
            $ts = strtotime($expires);
            if ($ts !== false && $ts < time()) {
                return 'expired';
            }
        }

        return 'active';
    }

    /** @return array<int,string> */
    private function decodeList(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    private function currentUserUuid(Request $request): ?string
    {
        return ActorHelper::uuidFromRequest($request);
    }

    private function canManageTenancy(string $userUuid): bool
    {
        return PermissionManager::getInstance(null, $this->context)
            ->can($userUuid, 'tenancy.manage', 'thallo');
    }

    private function tenantExists(string $tenantUuid): bool
    {
        return $this->connection->table('tenants')
            ->where('uuid', $tenantUuid)
            ->whereNull('deleted_at')
            ->first() !== null;
    }

    private function normalizeTenant(?string $tenantUuid): ?string
    {
        $tenantUuid = trim((string) $tenantUuid);
        return $tenantUuid === '' ? null : $tenantUuid;
    }

    /**
     * Trimmed, de-duplicated list of non-empty strings (scopes / allowed IPs).
     *
     * @param array<int,mixed> $values
     * @return list<string>
     */
    private function cleanList(array $values): array
    {
        $clean = [];
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                $clean[] = trim($value);
            }
        }

        return array_values(array_unique($clean));
    }

    /**
     * Normalize the optional expiry to 'Y-m-d H:i:s'. Returns null when absent/blank, false when the
     * value is present but unparseable (a validation error, not "no expiry").
     *
     * @return string|null|false
     */
    private function expiryField(?string $raw): string|null|false
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        $ts = strtotime($raw);
        if ($ts === false) {
            return false;
        }

        return date('Y-m-d H:i:s', $ts);
    }
}
