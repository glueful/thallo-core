<?php

declare(strict_types=1);

namespace Thallo\Core\Http\Controllers;

use Thallo\Core\Signup\MemberSignupService;
use Thallo\Core\Signup\SignupChallenge;
use Thallo\Core\Signup\SignupConfig;
use Thallo\Core\Signup\SignupCoordinator;
use Thallo\Core\Signup\SignupException;
use Thallo\Core\Signup\SignupRolePolicy;
use Thallo\Core\Signup\SignupTelemetry;
use Thallo\Core\Signup\WorkspaceSignupService;
use Thallo\Core\Support\ActorHelper;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Tenancy\Tenant\SingleStoreTenant;
use Thallo\Tenancy\System\SystemFlags;

final class SignupController
{
    public function __construct(
        private readonly MemberSignupService $members,
        private readonly WorkspaceSignupService $workspaces,
        private readonly SignupCoordinator $coordinator,
        private readonly SignupChallenge $challenge,
        private readonly SignupTelemetry $telemetry,
        private readonly SignupConfig $config,
        private readonly SignupRolePolicy $roles,
        private readonly SingleStoreTenant $singleStore,
        private readonly SystemFlags $flags,
    ) {
    }

    public function member(Request $request): Response
    {
        return $this->handle(function () use ($request): Response {
            $this->assertChallenge($request);
            return $this->accepted($this->members->begin($this->body($request), $this->ip($request)));
        });
    }

    public function workspace(Request $request): Response
    {
        return $this->handle(function () use ($request): Response {
            $this->assertChallenge($request);
            return $this->accepted($this->workspaces->beginAnonymous($this->body($request), $this->ip($request)));
        });
    }

    public function workspaceAuthenticated(Request $request): Response
    {
        return $this->handle(function () use ($request): Response {
            $this->assertChallenge($request);
            $actor = ActorHelper::uuidFromRequest($request);
            if ($actor === null) {
                throw new SignupException('Unauthenticated.', 401);
            }
            return Response::success($this->workspaces->beginAuthenticated(
                $this->body($request),
                $this->ip($request),
                $actor,
            ));
        });
    }

    public function verify(Request $request): Response
    {
        return $this->handle(function () use ($request): Response {
            $body = $this->body($request);
            return Response::success($this->coordinator->verify(
                $this->requiredString($body, 'intent_uuid'),
                $this->requiredString($body, 'code'),
            ));
        });
    }

    public function continue(Request $request): Response
    {
        return $this->handle(function () use ($request): Response {
            $body = $this->body($request);
            return Response::success($this->coordinator->continue(
                $this->requiredString($body, 'intent_uuid'),
                $this->requiredString($body, 'continuation_token'),
                $this->requiredString($body, 'operation_id'),
                $this->requiredString($body, 'operation'),
                is_array($body['payload'] ?? null) ? $body['payload'] : [],
            ));
        });
    }

    public function reverify(Request $request): Response
    {
        return $this->handle(function () use ($request): Response {
            $body = $this->body($request);
            return $this->accepted($this->coordinator->reverify(
                $this->requiredString($body, 'intent_uuid'),
                $this->ip($request),
            ));
        });
    }

    public function join(Request $request): Response
    {
        return $this->handle(function () use ($request): Response {
            $actor = ActorHelper::uuidFromRequest($request);
            if ($actor === null) {
                throw new SignupException('Unauthenticated.', 401);
            }
            return Response::success($this->members->joinAuthenticated($this->singleStore->resolve(), $actor));
        });
    }

    public function memberSettings(Request $request): Response
    {
        return $this->handle(function (): Response {
            $tenant = $this->singleStore->resolve();
            return $this->memberSettingsResponse($tenant);
        });
    }

    public function updateMemberSettings(Request $request): Response
    {
        return $this->handle(function () use ($request): Response {
            $body = $this->body($request);
            $tenant = $this->singleStore->resolve();
            $this->config->setMemberSignup(
                $tenant,
                filter_var($body['enabled'] ?? false, FILTER_VALIDATE_BOOL),
                is_string($body['role'] ?? null) ? $body['role'] : 'viewer',
            );
            return $this->memberSettings($request);
        });
    }

    public function workspaceSettings(Request $request): Response
    {
        return Response::success(['settings' => [
            'enabled' => $this->config->workspaceSignupConfigured(),
            'effective' => $this->config->workspaceSignupEnabled(),
            'email_channel_available' => $this->config->emailChannelAvailable(),
        ]]);
    }

    public function singleStoreMemberSettings(Request $request): Response
    {
        return $this->handle(fn (): Response => $this->memberSettingsResponse(
            $this->singleStoreTenantUuid(),
        ));
    }

    public function updateSingleStoreMemberSettings(Request $request): Response
    {
        return $this->handle(function () use ($request): Response {
            $body = $this->body($request);
            $tenant = $this->singleStoreTenantUuid();
            $this->config->setMemberSignup(
                $tenant,
                filter_var($body['enabled'] ?? false, FILTER_VALIDATE_BOOL),
                is_string($body['role'] ?? null) ? $body['role'] : 'viewer',
            );
            return $this->memberSettingsResponse($tenant);
        });
    }

    public function updateWorkspaceSettings(Request $request): Response
    {
        return $this->handle(function () use ($request): Response {
            $body = $this->body($request);
            $this->config->setWorkspaceSignup(filter_var(
                $body['enabled'] ?? false,
                FILTER_VALIDATE_BOOL,
            ));
            return $this->workspaceSettings($request);
        });
    }

    private function assertChallenge(Request $request): void
    {
        if ($this->challenge->validate($request)) {
            return;
        }
        $this->telemetry->record('signup.challenge_failed');
        throw new SignupException('Signup challenge failed.', 403);
    }

    private function singleStoreTenantUuid(): string
    {
        if ($this->flags->tenancyEnabled()) {
            throw new SignupException('Single-store signup settings are unavailable while tenancy is enabled.', 409);
        }
        return $this->singleStore->defaultUuidOrNull()
            ?? throw new SignupException(
                'No single-store workspace is established. Run thallo:tenancy:single-store:repair.',
                503,
            );
    }

    private function memberSettingsResponse(string $tenantUuid): Response
    {
        return Response::success(['settings' => [
            'enabled' => $this->config->memberSignupEnabled($tenantUuid),
            'role' => $this->config->memberSignupRole($tenantUuid),
            'email_channel_available' => $this->config->emailChannelAvailable(),
            'eligible_roles' => $this->roles->eligibleRoles($tenantUuid),
        ]]);
    }

    private function accepted(array $data): Response
    {
        $response = Response::success($data, 'Signup request accepted.');
        $response->setStatusCode(Response::HTTP_ACCEPTED);
        return $response;
    }

    private function handle(callable $operation): Response
    {
        try {
            return $operation();
        } catch (SignupException $exception) {
            $details = $exception->errors;
            if ($exception->errorCode !== null) {
                $details['code'] = $exception->errorCode;
            }
            return Response::error(
                $exception->getMessage(),
                $exception->status,
                $details === [] ? null : $details,
            );
        }
    }

    /** @return array<string,mixed> */
    private function body(Request $request): array
    {
        $body = json_decode((string) $request->getContent(), true);
        return is_array($body) ? $body : [];
    }

    /** @param array<string,mixed> $body */
    private function requiredString(array $body, string $key): string
    {
        $value = is_string($body[$key] ?? null) ? trim($body[$key]) : '';
        if ($value === '') {
            throw new SignupException("{$key} is required.", 422, [$key => 'Required.']);
        }
        return $value;
    }

    private function ip(Request $request): string
    {
        return (string) ($request->getClientIp() ?? 'unknown');
    }
}
