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
use Generator;
use InvalidArgumentException;
use IteratorAggregate;
use Override;
use PDO;
use PDOStatement;
use stdClass;

use function is_array;

/**
 * Wraps an executed PDOStatement. Choose a fetch mode (`fetchAssoc()`, `fetchClass()`, ...)
 * then read rows with `all()`, `first()` or `next()`, or iterate it (`foreach`, `lazy()`) to
 * hydrate one row at a time.
 *
 * @template TRow
 *
 * @implements IteratorAggregate<int, TRow>
 */
class ResultSet implements IteratorAggregate
{
    private const int FETCH_FUNC = PDO::FETCH_FUNC;

    private const int FETCH_GROUP = PDO::FETCH_GROUP;

    private const int FETCH_GROUP_UNIQUE = PDO::FETCH_GROUP | PDO::FETCH_UNIQUE;

    /** @var array<string, string|Closure(mixed): mixed> column => cast */
    private array $casts = [];

    private ?RowCaster $caster = null;

    /** @var class-string|null Set by fetchClass() */
    private ?string $class = null;

    /** @var list<mixed> */
    private array $ctorArgs = [];

    public function __construct(protected PDOStatement $statement)
    {
    }

    public function __destruct()
    {
        $this->statement->closeCursor();
    }

    /**
     * Number of rows affected by the statement.
     */
    public function count(): int
    {
        return $this->statement->rowCount();
    }

    /**
     * @param callable|null $callable Called with each row's columns as arguments (PDO::FETCH_FUNC)
     *
     * @return ($callable is null ? ($fetchStyle is 0 ? list<TRow> : array<mixed>) : list<mixed>)
     */
    public function all(?callable $callable = null, int $fetchStyle = 0): array
    {
        if ($callable === null && $fetchStyle === 0 && $this->caster !== null) {
            $rows = [];
            while (($row = $this->fetchRow()) !== false) {
                $rows[] = $row;
            }

            return $rows;
        }

        if ($callable === null) {
            return $this->statement->fetchAll($fetchStyle);
        }

        return $this->statement->fetchAll($this->withFunc($fetchStyle), $callable);
    }

    /**
     * Yields the rows one at a time instead of building an array, and closes the cursor when
     * done (or when the loop stops early). On MySQL the driver still buffers the whole result
     * unless the query was run with `stream()`.
     *
     * @return ($fetchStyle is 0 ? Generator<int, TRow, mixed, void> : Generator<int, mixed, mixed, void>)
     */
    public function lazy(int $fetchStyle = 0): Generator
    {
        try {
            while (true) {
                /** @var TRow|false $row */
                $row = $fetchStyle === 0 ? $this->fetchRow() : $this->statement->fetch($fetchStyle);
                if ($row === false) {
                    return;
                }

                yield $row;
            }
        } finally {
            $this->statement->closeCursor();
        }
    }

    /**
     * `foreach ($db->from('t')->select() as $row)` iterates lazily, see lazy().
     *
     * @return Generator<int, TRow, mixed, void>
     */
    #[Override]
    public function getIterator(): Generator
    {
        /** @var Generator<int, TRow, mixed, void> */
        return $this->lazy();
    }

    /**
     * Fetches all rows grouped by the first column.
     *
     * @return array<mixed>
     */
    public function allGroup(bool $uniq = false, ?callable $callable = null): array
    {
        $fetchStyle = $uniq ? self::FETCH_GROUP_UNIQUE : self::FETCH_GROUP;

        if ($callable === null) {
            return $this->statement->fetchAll($fetchStyle);
        }

        return $this->statement->fetchAll($this->withFunc($fetchStyle), $callable);
    }

    /**
     * Fetches the first row and closes the cursor; false when there are no rows.
     *
     * @template TResult
     *
     * @param (callable(mixed...): TResult)|null $callable Called with the row's columns as named arguments
     *
     * @return ($callable is null ? TRow|false : TResult|false)
     */
    public function first(?callable $callable = null): mixed
    {
        try {
            if ($callable === null) {
                return $this->fetchRow();
            }

            /** @var array<string, mixed>|false $assoc */
            $assoc = $this->statement->fetch(PDO::FETCH_ASSOC);

            return $assoc === false ? false : $callable(...$assoc);
        } finally {
            $this->statement->closeCursor();
        }
    }

    /**
     * @return TRow|false
     */
    public function next(): mixed
    {
        return $this->fetchRow();
    }

    /**
     * Converts column values as rows are read, for `all()`, `first()`, `next()` and iteration:
     * `->cast(['meta' => 'json', 'active' => 'bool', 'created_at' => 'datetime'])`.
     *
     * Casts: `int`, `float`, `bool`, `string`, `json` (decoded to arrays), `datetime`
     * (DateTimeImmutable) or a closure. NULL stays NULL. With fetchClass(), the row is converted
     * before the object is built, so typed properties (`public array $meta`) receive the
     * converted value; only declared properties are set, then the constructor runs.
     *
     * @param array<string, string|Closure(mixed): mixed> $casts column => cast
     *
     * @throws InvalidArgumentException On an unknown cast name
     */
    public function cast(array $casts): static
    {
        $this->casts = $casts + $this->casts;
        $this->caster = new RowCaster($this->casts);

        return $this;
    }

    public function flush(): bool
    {
        return $this->statement->closeCursor();
    }

    public function column(int $col = 0): mixed
    {
        return $this->statement->fetchColumn($col);
    }

    /**
     * @return self<array<string, mixed>>
     */
    public function fetchAssoc(): self
    {
        $this->statement->setFetchMode(PDO::FETCH_ASSOC);
        $this->class = null;

        /** @var self<array<string, mixed>> $result */
        $result = $this->rebind();

        return $result;
    }

    /**
     * @return self<\stdClass>
     */
    public function fetchObject(): self
    {
        $this->statement->setFetchMode(PDO::FETCH_OBJ);
        $this->class = null;

        /** @var self<\stdClass> $result */
        $result = $this->rebind();

        return $result;
    }

    /**
     * @return self<array<string, mixed>>
     */
    public function fetchNamed(): self
    {
        $this->statement->setFetchMode(PDO::FETCH_NAMED);
        $this->class = null;

        /** @var self<array<string, mixed>> $result */
        $result = $this->rebind();

        return $result;
    }

    /**
     * @return self<list<mixed>>
     */
    public function fetchNum(): self
    {
        $this->statement->setFetchMode(PDO::FETCH_NUM);
        $this->class = null;

        /** @var self<list<mixed>> $result */
        $result = $this->rebind();

        return $result;
    }

    /**
     * @return self<array<int|string, mixed>>
     */
    public function fetchBoth(): self
    {
        $this->statement->setFetchMode(PDO::FETCH_BOTH);
        $this->class = null;

        /** @var self<array<int|string, mixed>> $result */
        $result = $this->rebind();

        return $result;
    }

    /**
     * @return self<mixed>
     */
    public function fetchKeyPair(): self
    {
        $this->statement->setFetchMode(PDO::FETCH_KEY_PAIR);
        $this->class = null;

        /** @var self<mixed> $result */
        $result = $this->rebind();

        return $result;
    }

    /**
     * @template TClass of object
     *
     * @param class-string<TClass> $class
     * @param list<mixed> $ctorargs
     *
     * @return self<TClass>
     */
    public function fetchClass(string $class, array $ctorargs = []): self
    {
        $this->statement->setFetchMode(PDO::FETCH_CLASS, $class, $ctorargs);
        $this->class = $class;
        $this->ctorArgs = $ctorargs;

        /** @var self<TClass> $result */
        $result = $this->rebind();

        return $result;
    }

    /**
     * @param Closure(PDOStatement): mixed $func Configures the statement directly
     */
    public function fetchCustom(Closure $func): static
    {
        $func($this->statement);
        $this->class = null;

        return $this;
    }

    /**
     * The next row in the current fetch mode, with the casts applied.
     *
     * @return TRow|false
     */
    private function fetchRow(): mixed
    {
        $caster = $this->caster;
        if ($caster === null) {
            /** @var TRow|false */
            return $this->statement->fetch();
        }

        if ($this->class !== null) {
            /** @var array<string, mixed>|false $row */
            $row = $this->statement->fetch(PDO::FETCH_ASSOC);

            /** @var TRow|false */
            return $row === false ? false : $caster->hydrate($this->class, $caster->castArray($row), $this->ctorArgs);
        }

        /** @var mixed $row */
        $row = $this->statement->fetch();
        if (is_array($row)) {
            $row = $caster->castArray($row);
        } elseif ($row instanceof stdClass) {
            $row = (object) $caster->castArray((array) $row);
        }

        /** @var TRow|false */
        return $row;
    }

    /**
     * Adds PDO::FETCH_FUNC to a fetch style.
     */
    private function withFunc(int $fetchStyle): int
    {
        return $fetchStyle | self::FETCH_FUNC;
    }

    /**
     * Returns this instance untyped, so the fetch-mode setters can re-bind the row type.
     */
    private function rebind(): mixed
    {
        return $this;
    }
}
