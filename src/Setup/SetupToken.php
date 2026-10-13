<?php

declare(strict_types=1);

namespace Thallo\Core\Setup;

use Glueful\Installer\EnvWriter;

/**
 * SETUP_TOKEN gates the unauthenticated first-run setup. Once the first admin exists, by the web
 * form or `thallo:create-admin`, setup locks itself and the token has no further use, so it must
 * not linger in `.env`. Where PHP-FPM cannot write `.env`, provision (run as the site's owner, and
 * by every update) blanks it instead.
 */
final class SetupToken
{
    /** Blank it; best-effort, never fails the setup itself. */
    public static function clear(string $envPath): void
    {
        try {
            if (is_file($envPath) && ((new EnvWriter($envPath))->get('SETUP_TOKEN') ?? '') !== '') {
                (new EnvWriter($envPath))->set('SETUP_TOKEN', '');
            }
        } catch (\Throwable $e) {
            // PHP-FPM may not be allowed to write .env (the code belongs to another user).
            error_log('Setup: could not clear SETUP_TOKEN from .env (' . $e->getMessage()
                . '); setup is locked, and the next `php glueful thallo:provision` clears it.');
        }
    }
}
