<?php

declare(strict_types=1);

namespace Noirapi\Database\Test\Integration;

/**
 * Shared server scenarios on MySql. Configure with NOIRAPI_DB_MYSQL_DSN, _USER and _PASSWORD
 * (defaults match tests/docker/compose.yml); skipped without the driver or the server.
 */
final class MySqlScenariosTest extends ServerScenarios
{
    /**
     * @return array{string, string, string, string}
     */
    protected static function server(): array
    {
        return [
            'pdo_mysql',
            self::env('NOIRAPI_DB_MYSQL_DSN', 'mysql:host=localhost;dbname=test'),
            self::env('NOIRAPI_DB_MYSQL_USER', 'test'),
            self::env('NOIRAPI_DB_MYSQL_PASSWORD', 'test'),
        ];
    }
}
