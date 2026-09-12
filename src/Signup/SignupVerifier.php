<?php

declare(strict_types=1);

namespace Thallo\Core\Signup;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Users\Services\EmailVerification;
use Glueful\Security\OTP;

final class SignupVerifier
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly Connection $connection,
        private readonly SignupMailSender $mail,
        private readonly SignupTelemetry $telemetry,
    ) {
    }

    public function issue(string $intentUuid, string $email): void
    {
        $ttl = max(60, (int) config($this->context, 'signup.otp.ttl_seconds', 900));
        $otp = (new EmailVerification(context: $this->context))->generateOTP();
        $now = gmdate('Y-m-d H:i:s');
        $expires = gmdate('Y-m-d H:i:s', time() + $ttl);
        $existing = $this->connection->table('signup_verifiers')
            ->where('intent_uuid', '=', $intentUuid)->first();
        $row = [
            'otp_hash' => OTP::hashOTP($otp),
            'attempts' => 0,
            'expires_at' => $expires,
            'consumed_at' => null,
            'updated_at' => $now,
        ];
        if ($existing === null) {
            $this->connection->table('signup_verifiers')->insert($row + [
                'intent_uuid' => $intentUuid,
                'created_at' => $now,
            ]);
        } else {
            $this->connection->table('signup_verifiers')
                ->where('intent_uuid', '=', $intentUuid)->update($row);
        }

        try {
            $this->mail->sendVerification($intentUuid, $email, $otp, $ttl);
        } catch (\Throwable $exception) {
            $this->connection->table('signup_verifiers')->where('intent_uuid', '=', $intentUuid)->delete();
            throw $exception;
        }
    }

    public function verify(string $intentUuid, string $otp): bool
    {
        $limit = max(1, (int) config($this->context, 'signup.otp.attempts', 5));
        $result = $this->connection->transaction(function () use ($intentUuid, $otp, $limit): bool {
            $row = $this->connection->query()->executeRawFirst(
                'SELECT *, expires_at <= CURRENT_TIMESTAMP AS is_expired '
                . 'FROM signup_verifiers WHERE intent_uuid = ? FOR UPDATE',
                [$intentUuid],
            );
            if (
                $row === null
                || $row['consumed_at'] !== null
                || $this->truthy($row['is_expired'] ?? false)
                || (int) $row['attempts'] >= $limit
            ) {
                return false;
            }
            if (!OTP::verifyHashedOTP($otp, (string) $row['otp_hash'])) {
                $attempts = (int) $row['attempts'] + 1;
                $this->connection->table('signup_verifiers')
                    ->where('intent_uuid', '=', $intentUuid)
                    ->update(['attempts' => $attempts, 'updated_at' => gmdate('Y-m-d H:i:s')]);
                return false;
            }
            $this->connection->table('signup_verifiers')
                ->where('intent_uuid', '=', $intentUuid)
                ->update([
                    'consumed_at' => gmdate('Y-m-d H:i:s'),
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
            return true;
        });
        if (!$result) {
            $this->telemetry->record('signup.otp_failed', ['intent_uuid' => $intentUuid]);
        }
        return $result;
    }

    private function truthy(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }
}
