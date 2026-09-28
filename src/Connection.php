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

use Closure;
use PDO;
use Pdo\Mysql;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;

use function array_replace;
use function array_shift;
use function array_slice;
use function count;
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
use function preg_match;
use function preg_replace_callback;
use function random_int;
use function stripos;
use function usleep;
use function version_compare;

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
        'pgsql' => SQL\Compiler\PostgreSQL::class,
        'sqlite' => SQL\Compiler\SQLite::class,
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

    /** Keep only the newest entries in the query log; 0 keeps everything. */
    protected int $logLimit = 0;

    /** @var (Closure(string, list<mixed>, float): void)|null */
    protected ?Closure $queryListener = null;

    protected bool $reconnect = false;

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

    /**
     * @param int $limit Keep only the newest entries (for long-running processes); 0 keeps all
     */
    public function logQueries(bool $value = true, int $limit = 0): static
    {
        $this->logQueries = $value;
        $this->logLimit = $limit;

        return $this;
    }

    public function clearLog(): static
    {
        $this->log = [];

        return $this;
    }

    /**
     * Called after every executed statement, also failed ones, with the SQL, the parameters and
     * the duration in seconds; independent of logQueries(). Pass null to remove it.
     *
     * @param (Closure(string, list<mixed>, float): void)|null $listener
     */
    public function onQuery(?Closure $listener): static
    {
        $this->queryListener = $listener;

        return $this;
    }

    /**
     * When the server has closed the connection (MySQL "server has gone away" / "lost
     * connection", e.g. after wait_timeout in a long-running worker), reconnect and run the
     * statement once more. Never inside a transaction, whose work would be lost.
     */
    public function reconnectOnLostConnection(bool $value = true): static
    {
        $this->reconnect = $value;

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
     * @psalm-taint-sink sql $query
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
            if ($this->compiler instanceof SQL\Compiler\MySQL && $this->supportsRowAlias()) {
                $this->compiler->useRowAlias();
            }
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
     * @psalm-taint-sink sql $sql
     */
    public function query(string $sql, array $params = []): ResultSet
    {
        return new ResultSet($this->run($sql, $params)['statement']);
    }

    /**
     * @param list<mixed> $params
     *
     * @throws PDOException
     * @psalm-taint-sink sql $sql
     */
    public function command(string $sql, array $params = []): bool
    {
        $this->run($sql, $params);

        return true;
    }

    /**
     * Like query(), but on MySQL the rows are not buffered client-side: they are read from the
     * server while iterating, so huge results use constant memory. Until the ResultSet is fully
     * read (or destroyed) the connection cannot run other queries. Other drivers behave as query().
     *
     * @param list<mixed> $params
     *
     * @return ResultSet<mixed>
     *
     * @throws PDOException
     * @psalm-taint-sink sql $sql
     */
    public function stream(string $sql, array $params = []): ResultSet
    {
        if ($this->getDriver() !== 'mysql') {
            return $this->query($sql, $params);
        }

        // pdo_mysql reads the buffering mode from the connection when the statement executes
        $pdo = $this->getPDO();
        $buffered = $pdo->getAttribute(Mysql::ATTR_USE_BUFFERED_QUERY);
        $pdo->setAttribute(Mysql::ATTR_USE_BUFFERED_QUERY, false);

        try {
            $prepared = $this->prepare($sql, $params);
            $this->execute($prepared);
        } finally {
            $pdo->setAttribute(Mysql::ATTR_USE_BUFFERED_QUERY, $buffered);
        }

        return new ResultSet($prepared['statement']);
    }

    /**
     * Executes the statement and returns the affected row count.
     *
     * @param list<mixed> $params
     *
     * @throws PDOException
     * @psalm-taint-sink sql $sql
     */
    public function count(string $sql, array $params = []): int
    {
        $prepared = $this->run($sql, $params);
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
     * @psalm-taint-sink sql $sql
     */
    public function column(string $sql, array $params = []): mixed
    {
        $prepared = $this->run($sql, $params);

        try {
            return $prepared['statement']->fetchColumn();
        } finally {
            $prepared['statement']->closeCursor();
        }
    }

    /**
     * Runs the callback inside a transaction (or directly when one is already open).
     *
     * Any exception rolls the transaction back. A PDOException then returns $default (or is
     * re-thrown with throwTransactionExceptions()); any other exception is always re-thrown.
     *
     * With $attempts > 1, a transaction that fails because of a deadlock or a lock wait timeout
     * (see isRetryable()) is rolled back and run again, after a short growing pause. The
     * callback must then be safe to run more than once.
     *
     * @template TResult
     * @template TDefault
     *
     * @param callable(mixed): TResult $callback
     * @param TDefault $default Returned when the transaction fails and exceptions are not rethrown
     * @param positive-int $attempts How often to run the transaction when it deadlocks
     *
     * @return TResult|TDefault
     *
     * @throws PDOException When throwTransactionExceptions() is enabled
     * @throws Throwable Any non-PDO exception from the callback, after the rollback
     */
    public function transaction(
        callable $callback,
        mixed $that = null,
        mixed $default = null,
        int $attempts = 1,
    ): mixed {
        $pdo = $this->getPDO();

        if ($pdo->inTransaction()) {
            return $callback($that ?? $this);
        }

        for ($attempt = 1;; $attempt++) {
            $pdo->beginTransaction();

            try {
                $result = $callback($that ?? $this);
                $pdo->commit();

                return $result;
            } catch (Throwable $exception) {
                $this->rollBackOpenTransaction($pdo);
                if (!$exception instanceof PDOException) {
                    throw $exception;
                }

                if ($attempt < $attempts && self::isRetryable($exception)) {
                    usleep(random_int(10_000, 50_000) * $attempt);
                    continue;
                }

                if ($this->throwTransactionExceptions) {
                    throw $exception;
                }

                return $default;
            }
        }
    }

    /**
     * Deadlocks and lock wait timeouts, which succeed when simply run again: SQLSTATE 40001
     * (MySQL deadlock, SQL Server deadlock victim, serialization failure), 40P01 (PostgreSQL
     * deadlock), MySQL 1213 / 1205 and SQL Server 1205.
     */
    public static function isRetryable(PDOException $exception): bool
    {
        $info = $exception->errorInfo ?? [];

        return in_array($info[0] ?? null, ['40001', '40P01'], true) || in_array($info[1] ?? null, [1205, 1213], true);
    }

    /**
     * Rolls back, tolerating a transaction that the callback or a failed COMMIT already ended,
     * so the original exception is the one that surfaces.
     */
    private function rollBackOpenTransaction(PDO $pdo): void
    {
        try {
            $pdo->rollBack();
        } catch (PDOException) {
            // no active transaction left to roll back
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
            throw $this->withQuery($e, $query, $params);
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
            throw $this->withQuery($e, $prepared['query'], $prepared['params']);
        } finally {
            $this->record($prepared, microtime(true) - $start);
        }
    }

    /**
     * Prepares and executes, reconnecting once when enabled and the connection was lost.
     *
     * @param list<mixed> $params
     *
     * @return Prepared
     *
     * @throws PDOException
     */
    private function run(string $sql, array $params): array
    {
        try {
            $prepared = $this->prepare($sql, $params);
            $this->execute($prepared);

            return $prepared;
        } catch (PDOException $e) {
            if (!$this->reconnect || !self::isLostConnection($e) || $this->pdo?->inTransaction() === true) {
                throw $e;
            }
        }

        $this->disconnect();
        $prepared = $this->prepare($sql, $params);
        $this->execute($prepared);

        return $prepared;
    }

    /**
     * MySQL client errors 2006 (gone away), 2013 (lost during query) and 4031 (disconnected
     * for inactivity), and the messages PostgreSQL / others use for a closed connection.
     */
    private static function isLostConnection(PDOException $exception): bool
    {
        if (in_array($exception->errorInfo[1] ?? null, [2006, 2013, 4031], true)) {
            return true;
        }

        $pattern = '/server has gone away|lost connection|no connection to the server|server closed the connection/i';

        return preg_match($pattern, $exception->getMessage()) === 1;
    }

    /**
     * The driver's exception with the query appended to its message, keeping its errorInfo
     * (driver error codes such as 1062) and chaining the original.
     *
     * @param list<mixed> $params
     */
    private function withQuery(PDOException $exception, string $query, array $params): PDOException
    {
        $wrapped = new PDOException(
            $exception->getMessage() . ' [ ' . $this->replaceParams($query, $params) . ' ] ',
            (int) $exception->getCode(),
            $exception,
        );
        $wrapped->errorInfo = $exception->errorInfo;

        return $wrapped;
    }

    /**
     * @param Prepared $prepared
     */
    private function record(array $prepared, float $seconds): void
    {
        if ($this->queryListener !== null) {
            ($this->queryListener)($prepared['query'], $prepared['params'], $seconds);
        }

        if (!$this->logQueries) {
            return;
        }

        $this->log[] = ['query' => $this->replaceParams($prepared['query'], $prepared['params']), 'time' => $seconds];
        if ($this->logLimit > 0 && count($this->log) > $this->logLimit) {
            $this->log = array_slice($this->log, -$this->logLimit);
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
     * MySQL 8.0.19+ (not MariaDB) supports `INSERT ... AS alias ON DUPLICATE KEY UPDATE`.
     * Only asked when a real connection is configured.
     *
     * @throws PDOException When connecting fails
     */
    private function supportsRowAlias(): bool
    {
        if ($this->pdo === null && ($this->dsn ?? '') === '') {
            return false;
        }

        $version = $this->getPDO()->getAttribute(PDO::ATTR_SERVER_VERSION);
        if (!is_string($version) || stripos($version, 'mariadb') !== false) {
            return false;
        }

        return preg_match('/^(\d+\.\d+\.\d+)/', $version, $match) === 1 && version_compare($match[1], '8.0.19', '>=');
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
