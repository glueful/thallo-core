<?php

declare(strict_types=1);

namespace Thallo\Core\Signup;

use Glueful\Database\Connection;
use Thallo\Contracts\Tenancy\SignupDiagnostics;

final class DefaultSignupDiagnostics implements SignupDiagnostics
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SignupConfig $config,
    ) {
    }

    public function check(): array
    {
        if (!$this->connection->getSchemaBuilder()->hasTable('signup_intents')) {
            return ['status' => 'info', 'detail' => 'Public signup schema is not installed.'];
        }
        $configured = $this->config->workspaceSignupConfigured();
        $email = $this->config->emailChannelAvailable();
        return [
            'status' => $configured && !$email ? 'fail' : 'ok',
            'detail' => [
                'workspace_signup_configured' => $configured,
                'workspace_signup_effective' => $this->config->workspaceSignupEnabled(),
                'email_channel_available' => $email,
            ],
        ];
    }
}
