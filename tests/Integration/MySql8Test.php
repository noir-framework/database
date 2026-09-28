<?php

declare(strict_types=1);

namespace Noirapi\Database\Test\Integration;

use Noirapi\Database\SQL\Compiler\MySQL;

/**
 * Shared server scenarios on MySQL 8 (upserts use the row alias there). Configure with
 * NOIRAPI_DB_MYSQL8_DSN, _USER and _PASSWORD (defaults match tests/docker/compose.yml).
 */
final class MySql8Test extends ServerScenarios
{
    public function testUpsertUsesRowAlias(): void
    {
        $compiler = $this->db->getConnection()->getCompiler();
        $this->assertInstanceOf(MySQL::class, $compiler);

        $sql = $compiler->insert($this->db->insert(['id' => 1, 'name' => 'x'])->upsert('id')->getSQLStatement());
        $compiler->getParams();
        $this->assertStringContainsString('AS `excluded` ON DUPLICATE KEY UPDATE `name` = `excluded`.`name`', $sql);
    }

    /**
     * @return array{string, string, string, string}
     */
    protected static function server(): array
    {
        return [
            'pdo_mysql',
            self::env('NOIRAPI_DB_MYSQL8_DSN', 'mysql:host=127.0.0.1;port=53307;dbname=test'),
            self::env('NOIRAPI_DB_MYSQL8_USER', 'test'),
            self::env('NOIRAPI_DB_MYSQL8_PASSWORD', 'test'),
        ];
    }
}
