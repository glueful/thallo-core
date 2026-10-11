<?php

declare(strict_types=1);

namespace Thallo\Core\Updates;

use Glueful\Bootstrap\ApplicationContext;

/**
 * The PostgreSQL connection the update backup dumps (plan ruling 1), resolved exactly as the
 * framework's DatabaseBackupTask resolves it: host, port, user, database, and the password and
 * sslmode carried in the tool's own environment, never on the command line.
 */
final class PgDumpConnection
{
    public function __construct(private readonly ApplicationContext $context)
    {
    }

    /** @return array{host:string,port:string,username:string,database:string,env:array<string,string>} */
    public function resolve(): array
    {
        $config = (array) config($this->context, 'database', []);
        $engine = (string) ($config['engine'] ?? 'sqlite');
        if ($engine !== 'pgsql') {
            throw new \RuntimeException("The update backup needs PostgreSQL; this install uses {$engine}.");
        }
        $block = (array) ($config['pgsql'] ?? []);
        $env = ['PGPASSWORD' => (string) ($block['pass'] ?? '')];
        $sslmode = (string) ($block['sslmode'] ?? '');
        if ($sslmode !== '') {
            $env['PGSSLMODE'] = $sslmode;
        }

        return [
            'host' => (string) ($block['host'] ?? '127.0.0.1'),
            'port' => (string) ($block['port'] ?? 5432),
            'username' => (string) ($block['user'] ?? ''),
            'database' => (string) ($block['db'] ?? ''),
            'env' => $env,
        ];
    }
}
