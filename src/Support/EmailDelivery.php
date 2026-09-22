<?php

declare(strict_types=1);

namespace Thallo\Core\Support;

/**
 * Reads `NotificationService::send()`'s answer for an email-only send. A synchronous send reports
 * `status: success` with the channel detail under `sync.channels`; a repeat of an idempotent send
 * reports `duplicate`, which means the first one went out. Anything else did not deliver.
 */
final class EmailDelivery
{
    /** @param array<string,mixed> $result */
    public static function delivered(array $result): bool
    {
        $status = $result['status'] ?? null;
        if ($status === 'duplicate') {
            return true;
        }
        $email = $result['sync']['channels']['email'] ?? null;

        return $status === 'success' && is_array($email) && ($email['status'] ?? null) === 'success';
    }
}
