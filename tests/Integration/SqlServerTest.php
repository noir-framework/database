<?php

declare(strict_types=1);

namespace Noirapi\Database\Test\Integration;

/**
 * Shared server scenarios on SqlServer. Configure with NOIRAPI_DB_SQLSRV_DSN, _USER and _PASSWORD
 * (defaults match tests/docker/compose.yml); skipped without the driver or the server.
 */
final class SqlServerTest extends ServerScenarios
{
    /**
     * @return array{string, string, string, string}
     */
    protected static function server(): array
    {
        return [
            'pdo_sqlsrv',
            self::env('NOIRAPI_DB_SQLSRV_DSN', 'sqlsrv:Server=localhost,51433;Database=master;TrustServerCertificate=1'),
            self::env('NOIRAPI_DB_SQLSRV_USER', 'sa'),
            self::env('NOIRAPI_DB_SQLSRV_PASSWORD', 'Noirapi-Test1'),
        ];
    }
}
