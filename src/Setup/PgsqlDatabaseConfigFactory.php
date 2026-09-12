<?php

declare(strict_types=1);

namespace Thallo\Core\Setup;

use Glueful\Installer\DatabaseConfig;
use Glueful\Installer\EnvWriter;

/**
 * Builds a Postgres DatabaseConfig for first-run setup. Thallo is Postgres-required, so the engine
 * is always 'pgsql' — never prompted. This is the single place that knows Thallo's DB_PGSQL_* env
 * key names; both interactive and --quiet (from-env) input funnel through here.
 */
final class PgsqlDatabaseConfigFactory
{
    /** The commented-out sample values shipped in .env.example; never real settings. */
    private const PLACEHOLDERS = [
        'database' => 'your_database_name',
        'username' => 'your_database_user',
        'password' => 'your_database_password',
    ];

    /**
     * True when .env already carries usable settings: database and username are non-empty and
     * none of database/username/password is an .env.example placeholder. An empty password is
     * allowed (trust auth) — only the literal placeholder disqualifies it.
     */
    public function isProvided(DatabaseConfig $config): bool
    {
        return $config->database !== ''
            && $config->username !== ''
            && $config->database !== self::PLACEHOLDERS['database']
            && $config->username !== self::PLACEHOLDERS['username']
            && $config->password !== self::PLACEHOLDERS['password'];
    }

    public function fromInput(
        string $host,
        int $port,
        string $database,
        string $username,
        string $password,
        ?string $schema = null,
        ?string $sslMode = null,
    ): DatabaseConfig {
        return new DatabaseConfig('pgsql', $host, $port, $database, $username, $password, $schema, $sslMode);
    }

    public function fromEnv(EnvWriter $env): DatabaseConfig
    {
        $schema = $env->get('DB_PGSQL_SCHEMA');
        $sslMode = $env->get('DB_PGSQL_SSL_MODE');

        return new DatabaseConfig(
            'pgsql',
            $env->get('DB_PGSQL_HOST') ?? 'localhost', // absent defaults; explicitly empty stays invalid
            $this->parsePort($env->get('DB_PGSQL_PORT')),
            $env->get('DB_PGSQL_DATABASE') ?? '',
            $env->get('DB_PGSQL_USERNAME') ?? '',
            $env->get('DB_PGSQL_PASSWORD') ?? '',
            ($schema === null || $schema === '') ? null : $schema,
            ($sslMode === null || $sslMode === '') ? null : $sslMode,
        );
    }

    /**
     * Absent => the pgsql default (5432). A non-numeric value (e.g. "5432abc") becomes 0 so it is
     * caught by requiredFieldErrors() rather than silently truncated by an (int) cast.
     */
    private function parsePort(?string $raw): int
    {
        return match (true) {
            $raw === null || $raw === '' => 5432,
            ctype_digit($raw) => (int) $raw,
            default => 0,
        };
    }

    /**
     * Whether a password was explicitly provided in .env — a present-but-empty
     * DB_PGSQL_PASSWORD= line means "none" (trust/peer auth) and counts as provided;
     * only a fully absent line does not. fromEnv() collapses both to '', so presence
     * must be derived here, at the input boundary, not from the config value.
     */
    public function passwordProvidedInEnv(EnvWriter $env): bool
    {
        return $env->get('DB_PGSQL_PASSWORD') !== null;
    }

    /**
     * The required-field names that are missing or invalid in $config. Empty list => valid.
     * Used to FAIL LOUDLY before the Installer runs — both in --quiet mode (env may be blank/partial)
     * and interactively (a prompt may have been answered empty).
     *
     * $passwordProvided carries explicit-empty ("none", valid for trust-auth PostgreSQL)
     * past the '' collapse; an unprovided password still refuses.
     *
     * @return list<string>
     */
    public function requiredFieldErrors(DatabaseConfig $config, bool $passwordProvided): array
    {
        $errors = [];
        if ($config->host === '') {
            $errors[] = 'host (DB_PGSQL_HOST)';
        }
        if ($config->port <= 0) {
            $errors[] = 'port (DB_PGSQL_PORT)';
        }
        if ($config->database === '') {
            $errors[] = 'database (DB_PGSQL_DATABASE)';
        }
        if ($config->username === '') {
            $errors[] = 'username (DB_PGSQL_USERNAME)';
        }
        if ($config->password === '' && !$passwordProvided) {
            $errors[] = 'password (DB_PGSQL_PASSWORD)';
        }
        return $errors;
    }
}
