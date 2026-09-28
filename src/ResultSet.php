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
use PDOStatement;

/**
 * Wraps an executed PDOStatement. Choose a fetch mode (`fetchAssoc()`, `fetchClass()`, ...)
 * then read rows with `all()`, `first()` or `next()`.
 *
 * @template TRow
 */
class ResultSet
{
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
        if ($callable === null) {
            return $this->statement->fetchAll($fetchStyle);
        }

        return $this->statement->fetchAll($fetchStyle | PDO::FETCH_FUNC, $callable);
    }

    /**
     * Fetches all rows grouped by the first column.
     *
     * @return array<mixed>
     */
    public function allGroup(bool $uniq = false, ?callable $callable = null): array
    {
        $fetchStyle = PDO::FETCH_GROUP | ($uniq ? PDO::FETCH_UNIQUE : 0);

        if ($callable === null) {
            return $this->statement->fetchAll($fetchStyle);
        }

        return $this->statement->fetchAll($fetchStyle | PDO::FETCH_FUNC, $callable);
    }

    /**
     * Fetches the first row and closes the cursor; false when there are no rows.
     *
     * @param callable|null $callable Called with the row's columns as arguments
     *
     * @return ($callable is null ? TRow|false : mixed)
     */
    public function first(?callable $callable = null): mixed
    {
        if ($callable === null) {
            $result = $this->statement->fetch();
            $this->statement->closeCursor();

            return $result;
        }

        $result = $this->statement->fetch(PDO::FETCH_ASSOC);
        $this->statement->closeCursor();

        return $result === false ? false : $callable(...$result);
    }

    /**
     * @return TRow|false
     */
    public function next(): mixed
    {
        return $this->statement->fetch();
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

        return $this;
    }

    /**
     * Returns this instance untyped, so the fetch-mode setters can re-bind the row type.
     */
    private function rebind(): mixed
    {
        return $this;
    }
}
