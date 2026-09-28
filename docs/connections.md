# Connections

A `Connection` holds the PDO settings and connects on first use. A `Database` wraps a
connection and is the entry point for queries; `Database::schema()` gives the schema builder.

```php
use Noirapi\Database\Connection;
use Noirapi\Database\Database;

$connection = new Connection('mysql:host=localhost;dbname=app', 'user', 'secret');
$db = new Database($connection);
```

The first argument is a PDO [DSN](https://www.php.net/manual/en/pdo.construct.php). The
driver name in it picks the SQL dialect: `mysql`, `pgsql`, `sqlite`, or `sqlsrv` / `dblib` /
`mssql` / `sybase` for SQL Server.

To reuse an existing PDO object:

```php
$connection = Connection::fromPDO($pdo);
```

`$connection->getDatabase()` returns a `Database` for the connection, created once and reused.

## PDO options

The defaults are exceptions on errors, rows as objects, no stringified fetches and native
prepared statements:

```php
PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
PDO::ATTR_STRINGIFY_FETCHES => false,
PDO::ATTR_EMULATE_PREPARES => false,
```

Change one option with `option()`, several with `options()`. Both must be called before the
first query, because they are applied when PDO connects.

```php
$connection->option(PDO::ATTR_TIMEOUT, 5);
$connection->options([
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_PERSISTENT => true,
]);
```

`persistent()` is a shortcut for `PDO::ATTR_PERSISTENT`.

## Commands run after connecting

```php
$connection->initCommand('SET NAMES utf8mb4');
$connection->initCommand('SET time_zone = ?', ['+00:00']);
```

## Query log

```php
$connection->logQueries();

// ... run queries ...

foreach ($connection->getLog() as $entry) {
    printf("%.4fs  %s\n", $entry['time'] ?? 0, $entry['query']);
}
```

Each entry is `['query' => string, 'time' => float]`, with the parameters inlined into the
query text. The log grows for the life of the connection, so in long-running processes
(workers, Swoole) cap it or clear it:

```php
$connection->logQueries(true, 500);   // keep the newest 500 entries
$connection->clearLog();
```

To send queries to your own logger or profiler instead, register a listener. It is called
after every statement, including failed ones, whether or not `logQueries()` is on:

```php
$connection->onQuery(function (string $sql, array $params, float $seconds) use ($logger): void {
    if ($seconds > 0.5) {
        $logger->warning('slow query', ['sql' => $sql, 'params' => $params, 'seconds' => $seconds]);
    }
});
```

## Errors

A failing statement throws a `PDOException` whose message ends with the SQL and its
parameters inlined. The driver's `errorInfo` is kept, and the original exception is available
as `getPrevious()`:

```php
try {
    $db->insert(['email' => $email])->into('users');
} catch (PDOException $e) {
    if (($e->errorInfo[1] ?? null) === 1062) {  // MySQL duplicate key
        // ...
    }
}
```

## Long-running processes

MySQL closes idle connections after `wait_timeout` (8 hours by default), and the next query
fails with "server has gone away". `reconnectOnLostConnection()` reconnects and runs the
statement once more (init commands run again on the new connection):

```php
$connection->reconnectOnLostConnection();
```

It never retries inside a transaction, because the work done before the connection dropped is
lost and replaying one statement would leave the transaction half done. The error is thrown
as usual in that case.

## Compiler options

```php
$connection->setDateFormat('Y-m-d H:i:s');   // format for DateTimeInterface parameters
$connection->setWrapperFormat('`%s`');       // identifier quoting, rarely needed
```

## Running raw SQL

```php
$rows = $connection->query('SELECT * FROM users WHERE id = ?', [5])->fetchAssoc()->all();
$ok = $connection->command('DELETE FROM sessions WHERE expires < ?', [time()]);   // bool
$affected = $connection->count('UPDATE users SET active = 0 WHERE seen < ?', [$cutoff]); // int
$name = $connection->column('SELECT name FROM users WHERE id = ?', [5]);          // first column
```

`$connection->stream($sql, $params)` is the unbuffered variant of `query()`, see
[Results handling](results-handling.md#streaming-large-results).

## Parameter binding

Values are bound with a PDO type that matches them: `null` as `PARAM_NULL`, integers as
`PARAM_INT`, booleans as `PARAM_BOOL`, stream resources as `PARAM_LOB` (to write a file or
memory stream into a BLOB without loading it into a string), everything else as `PARAM_STR`.
`DateTimeInterface` values are formatted with the date format above.

```php
$db->insert(['name' => 'photo.jpg', 'data' => fopen('/tmp/photo.jpg', 'rb')])->into('files');
```

## Last insert id

```php
$db->insert(['name' => 'Ann'])->into('users');
$id = $db->lastInsertId();                    // string|false, like PDO::lastInsertId()
```

After a multi-row insert, MySQL returns the id of the first inserted row. PostgreSQL needs the
sequence name: `$db->lastInsertId('users_id_seq')`.

## Supported drivers

| Driver | SQL compiler | Schema compiler |
|---|---|---|
| `mysql` | MySQL / MariaDB | MySQL |
| `pgsql` | PostgreSQL | PostgreSQL |
| `sqlite` | SQLite | SQLite |
| `sqlsrv`, `dblib`, `mssql`, `sybase` | SQL Server | SQL Server |
| anything else | generic | none (schema operations throw) |

`oci`, `firebird`, `ibm`, `odbc` and `nuodb` throw a `RuntimeException`: those dialects were
removed in 5.0.
