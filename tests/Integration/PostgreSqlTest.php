<?php

declare(strict_types=1);

namespace Noirapi\Database\Test\Integration;

/**
 * Shared server scenarios on PostgreSql. Configure with NOIRAPI_DB_PGSQL_DSN, _USER and _PASSWORD
 * (defaults match tests/docker/compose.yml); skipped without the driver or the server.
 */
final class PostgreSqlTest extends ServerScenarios
{
    /**
     * @return array{string, string, string, string}
     */
    protected static function server(): array
    {
        return [
            'pdo_pgsql',
            self::env('NOIRAPI_DB_PGSQL_DSN', 'pgsql:host=localhost;port=55432;dbname=test'),
            self::env('NOIRAPI_DB_PGSQL_USER', 'test'),
            self::env('NOIRAPI_DB_PGSQL_PASSWORD', 'test'),
        ];
    }
}
