<?php

/* ===========================================================================
 * Copyright 2018 Zindex Software
 * Copyright 2026 noir-framework
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *    http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 * ============================================================================ */

declare(strict_types=1);

namespace Noirapi\Database;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

use function array_replace;
use function array_shift;
use function get_debug_type;
use function get_resource_id;
use function in_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_object;
use function is_resource;
use function is_string;
use function microtime;
use function preg_replace_callback;

/**
 * Lazily connected PDO wrapper that picks the SQL and schema compilers for its driver.
 *
 * @psalm-type Prepared = array{query: string, params: list<mixed>, statement: PDOStatement}
 * @psalm-type LogEntry = array{query: string, time?: float}
 *
 * @phpstan-consistent-constructor
 * @psalm-consistent-constructor
 *
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity") opis/database API: settings, execution, logging.
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") Wires the SQL and schema dialect compilers.
 */
class Connection
{
    /** Driver names whose dialects were removed in 5.0. */
    private const array REMOVED_DRIVERS = ['oci', 'oracle', 'firebird', 'db2', 'ibm', 'odbc', 'nuodb'];

    private const array SQL_DIALECTS = [
        'mysql' => SQL\Compiler\MySQL::class,
        'dblib' => SQL\Compiler\SQLServer::class,
        'mssql' => SQL\Compiler\SQLServer::class,
        'sqlsrv' => SQL\Compiler\SQLServer::class,
        'sybase' => SQL\Compiler\SQLServer::class,
    ];

    private const array SCHEMA_DIALECTS = [
        'mysql' => Schema\Compiler\MySQL::class,
        'pgsql' => Schema\Compiler\PostgreSQL::class,
        'dblib' => Schema\Compiler\SQLServer::class,
        'mssql' => Schema\Compiler\SQLServer::class,
        'sqlsrv' => Schema\Compiler\SQLServer::class,
        'sybase' => Schema\Compiler\SQLServer::class,
        'sqlite' => Schema\Compiler\SQLite::class,
    ];

    protected bool $logQueries = false;

    /** @var list<LogEntry> */
    protected array $log = [];

    /** @var list<array{sql: string, params: list<mixed>}> */
    protected array $commands = [];

    /** @var array<int, mixed> */
    protected array $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
        PDO::ATTR_STRINGIFY_FETCHES => false,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    protected ?SQL\Compiler $compiler = null;

    protected ?Schema\Compiler $schemaCompiler = null;

    protected ?Schema $schema = null;

    protected ?Database $database = null;

    /** @var array<string, string> */
    protected array $compilerOptions = [];

    /** @var array<string, string> */
    protected array $schemaCompilerOptions = [];

    protected bool $throwTransactionExceptions = false;

    public function __construct(
        protected ?string $dsn = null,
        protected ?string $username = null,
        protected ?string $password = null,
        protected ?string $driver = null,
        protected ?PDO $pdo = null,
    ) {
    }

    public static function fromPDO(PDO $pdo): static
    {
        return new static(null, null, null, null, $pdo);
    }

    /**
     * @return array{username: ?string, password: ?string, logQueries: bool, options: array<int, mixed>,
     *     commands: list<array{sql: string, params: list<mixed>}>, dsn: ?string}
     */
    public function __serialize(): array
    {
        return [
            'username' => $this->username,
            'password' => $this->password,
            'logQueries' => $this->logQueries,
            'options' => $this->options,
            'commands' => $this->commands,
            'dsn' => $this->dsn,
        ];
    }

    /**
     * @param array{username: ?string, password: ?string, logQueries: bool, options: array<int, mixed>,
     *     commands: list<array{sql: string, params: list<mixed>}>, dsn: ?string} $data
     */
    public function __unserialize(array $data): void
    {
        $this->username = $data['username'];
        $this->password = $data['password'];
        $this->logQueries = $data['logQueries'];
        $this->options = $data['options'];
        $this->commands = $data['commands'];
        $this->dsn = $data['dsn'];
    }

    public function logQueries(bool $value = true): static
    {
        $this->logQueries = $value;

        return $this;
    }

    public function throwTransactionExceptions(bool $value = true): static
    {
        $this->throwTransactionExceptions = $value;

        return $this;
    }

    /**
     * Adds a command executed right after connecting (e.g. SET NAMES).
     *
     * @param list<mixed> $params
     */
    public function initCommand(string $query, array $params = []): static
    {
        $this->commands[] = ['sql' => $query, 'params' => $params];

        return $this;
    }

    public function username(string $username): static
    {
        $this->username = $username;

        return $this;
    }

    public function password(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    /**
     * @param array<int, mixed> $options PDO attribute => value
     */
    public function options(array $options): static
    {
        $this->options = array_replace($this->options, $options);

        return $this;
    }

    public function option(int $name, mixed $value): static
    {
        $this->options[$name] = $value;

        return $this;
    }

    public function persistent(bool $value = true): static
    {
        return $this->option(PDO::ATTR_PERSISTENT, $value);
    }

    public function setDateFormat(string $format): static
    {
        $this->compilerOptions['dateFormat'] = $format;

        return $this;
    }

    public function setWrapperFormat(string $wrapper): static
    {
        $this->compilerOptions['wrapper'] = $wrapper;
        $this->schemaCompilerOptions['wrapper'] = $wrapper;

        return $this;
    }

    public function getDSN(): ?string
    {
        return $this->dsn;
    }

    public function getDriver(): string
    {
        if ($this->driver === null) {
            $driver = $this->getPDO()->getAttribute(PDO::ATTR_DRIVER_NAME);
            $this->driver = is_string($driver) ? $driver : '';
        }

        return $this->driver;
    }

    public function getSchema(): Schema
    {
        return $this->schema ??= new Schema($this);
    }

    /**
     * A Database bound to this connection, created once.
     */
    public function getDatabase(): Database
    {
        return $this->database ??= new Database($this);
    }

    /**
     * @throws PDOException When connecting fails
     */
    public function getPDO(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = new PDO((string) $this->dsn, $this->username, $this->password, $this->options);
            foreach ($this->commands as $command) {
                $this->command($command['sql'], $command['params']);
            }
        }

        return $this->pdo;
    }

    /**
     * @throws RuntimeException When the driver's dialect is no longer supported
     */
    public function getCompiler(): SQL\Compiler
    {
        if ($this->compiler === null) {
            $driver = $this->getDriver();
            $this->assertDriverSupported($driver);
            $dialect = self::SQL_DIALECTS[$driver] ?? SQL\Compiler::class;
            $this->compiler = new $dialect();
            $this->compiler->setOptions($this->compilerOptions);
        }

        return $this->compiler;
    }

    /**
     * @throws RuntimeException When the driver has no schema compiler
     */
    public function schemaCompiler(): Schema\Compiler
    {
        if ($this->schemaCompiler === null) {
            $driver = $this->getDriver();
            $this->assertDriverSupported($driver);
            $dialect = self::SCHEMA_DIALECTS[$driver]
                ?? throw new RuntimeException('Schema not supported for driver: ' . $driver);
            $this->schemaCompiler = new $dialect($this);
            $this->schemaCompiler->setOptions($this->schemaCompilerOptions);
        }

        return $this->schemaCompiler;
    }

    /**
     * Closes the connection by dropping the PDO instance.
     */
    public function disconnect(): void
    {
        $this->pdo = null;
    }

    /**
     * @return list<LogEntry>
     */
    public function getLog(): array
    {
        return $this->log;
    }

    /**
     * @param list<mixed> $params
     *
     * @return ResultSet<mixed>
     *
     * @throws PDOException
     */
    public function query(string $sql, array $params = []): ResultSet
    {
        $prepared = $this->prepare($sql, $params);
        $this->execute($prepared);

        return new ResultSet($prepared['statement']);
    }

    /**
     * @param list<mixed> $params
     *
     * @throws PDOException
     */
    public function command(string $sql, array $params = []): bool
    {
        return $this->execute($this->prepare($sql, $params));
    }

    /**
     * Executes the statement and returns the affected row count.
     *
     * @param list<mixed> $params
     *
     * @throws PDOException
     */
    public function count(string $sql, array $params = []): int
    {
        $prepared = $this->prepare($sql, $params);
        $this->execute($prepared);
        $result = $prepared['statement']->rowCount();
        $prepared['statement']->closeCursor();

        return $result;
    }

    /**
     * Executes the statement and returns the first column of the first row (false when empty).
     *
     * @param list<mixed> $params
     *
     * @throws PDOException
     */
    public function column(string $sql, array $params = []): mixed
    {
        $prepared = $this->prepare($sql, $params);
        $this->execute($prepared);

        try {
            return $prepared['statement']->fetchColumn();
        } finally {
            $prepared['statement']->closeCursor();
        }
    }

    /**
     * Runs the callback inside a transaction (or directly when one is already open).
     *
     * @template TResult
     * @template TDefault
     *
     * @param callable(mixed): TResult $callback
     * @param TDefault $default Returned when the transaction fails and exceptions are not rethrown
     *
     * @return TResult|TDefault
     *
     * @throws PDOException When throwTransactionExceptions() is enabled
     */
    public function transaction(callable $callback, mixed $that = null, mixed $default = null): mixed
    {
        $pdo = $this->getPDO();

        if ($pdo->inTransaction()) {
            return $callback($that ?? $this);
        }

        try {
            $pdo->beginTransaction();
            $result = $callback($that ?? $this);
            $pdo->commit();

            return $result;
        } catch (PDOException $exception) {
            $pdo->rollBack();
            if ($this->throwTransactionExceptions) {
                throw $exception;
            }

            return $default;
        }
    }

    /**
     * Inlines the parameters into the query, for logging and error messages only.
     *
     * @param list<mixed> $params
     */
    protected function replaceParams(string $query, array $params): string
    {
        $compiler = $this->getCompiler();

        return preg_replace_callback('/\?/', static function () use (&$params, $compiler): string {
            /** @var mixed $param */
            $param = array_shift($params);

            return match (true) {
                is_object($param) => $compiler->quote($param::class),
                is_int($param), is_float($param) => (string) $param,
                $param === null => 'NULL',
                is_bool($param) => $param ? 'TRUE' : 'FALSE',
                is_string($param) => $compiler->quote($param),
                is_resource($param) => $compiler->quote('RESOURCE#' . get_resource_id($param)),
                default => $compiler->quote(get_debug_type($param)),
            };
        }, $query) ?? $query;
    }

    /**
     * @param list<mixed> $params
     *
     * @return Prepared
     *
     * @throws PDOException
     */
    protected function prepare(string $query, array $params): array
    {
        try {
            $statement = $this->getPDO()->prepare($query);
        } catch (PDOException $e) {
            throw new PDOException(
                $e->getMessage() . ' [ ' . $this->replaceParams($query, $params) . ' ] ',
                (int) $e->getCode(),
                $e->getPrevious(),
            );
        }

        return ['query' => $query, 'params' => $params, 'statement' => $statement];
    }

    /**
     * @param Prepared $prepared
     *
     * @throws PDOException
     */
    protected function execute(array $prepared): bool
    {
        $start = microtime(true);

        try {
            if ($prepared['params'] !== []) {
                $this->bindValues($prepared['statement'], $prepared['params']);
            }

            return $prepared['statement']->execute();
        } catch (PDOException $e) {
            throw new PDOException(
                $e->getMessage() . ' [ ' . $this->replaceParams($prepared['query'], $prepared['params']) . ' ] ',
                (int) $e->getCode(),
                $e->getPrevious(),
            );
        } finally {
            if ($this->logQueries) {
                $this->log[] = [
                    'query' => $this->replaceParams($prepared['query'], $prepared['params']),
                    'time' => microtime(true) - $start,
                ];
            }
        }
    }

    /**
     * @param list<mixed> $values
     */
    protected function bindValues(PDOStatement $statement, array $values): void
    {
        /** @var mixed $value */
        foreach ($values as $key => $value) {
            $type = match (true) {
                $value === null => PDO::PARAM_NULL,
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                is_resource($value) => PDO::PARAM_LOB,
                default => PDO::PARAM_STR,
            };
            $statement->bindValue($key + 1, $value, $type);
        }
    }

    /**
     * @throws RuntimeException
     */
    private function assertDriverSupported(string $driver): void
    {
        if (in_array($driver, self::REMOVED_DRIVERS, true)) {
            throw new RuntimeException('Driver "' . $driver . '" is not supported since noirapi/database 5.0');
        }
    }
}
