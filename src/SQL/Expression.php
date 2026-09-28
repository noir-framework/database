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

namespace Noirapi\Database\SQL;

use Closure;
use InvalidArgumentException;
use LogicException;
use Noirapi\Database\SQL\Clause\AggregateFunction;
use Noirapi\Database\SQL\Clause\AggregateName;
use Noirapi\Database\SQL\Clause\BitsPart;
use Noirapi\Database\SQL\Clause\CallPart;
use Noirapi\Database\SQL\Clause\ColumnPart;
use Noirapi\Database\SQL\Clause\DateArithmetic;
use Noirapi\Database\SQL\Clause\ExpressionPart;
use Noirapi\Database\SQL\Clause\FunctionName;
use Noirapi\Database\SQL\Clause\GroupPart;
use Noirapi\Database\SQL\Clause\JsonPart;
use Noirapi\Database\SQL\Clause\OperatorPart;
use Noirapi\Database\SQL\Clause\SqlFunction;
use Noirapi\Database\SQL\Clause\SubqueryPart;
use Noirapi\Database\SQL\Clause\ValuePart;

use function array_map;
use function array_values;
use function count;
use function is_array;
use function preg_match;

/**
 * A raw SQL expression assembled from columns, operators, values and functions.
 *
 * Any undefined property read appends that name as an operator, so
 * `$expr->column('a')->{'+'}->value(1)` produces `"a" + ?`, and any undefined method call
 * appends a function call, so `$expr->COALESCE(fn ($e) => $e->column('a'), 0)` produces
 * `COALESCE("a", ?)`.
 *
 * @psalm-type ColumnArg = string|Expression|(Closure(Expression): mixed)
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") Creates every expression token type.
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity") One small method per SQL function / operator.
 */
class Expression
{
    /** @var list<ExpressionPart> */
    protected array $expressions = [];

    /**
     * @param Closure(Expression): mixed $func
     */
    public static function fromClosure(Closure $func): self
    {
        $expression = new self();
        $func($expression);

        return $expression;
    }

    public static function fromColumn(string $column): self
    {
        return (new self())->column($column);
    }

    /**
     * @throws InvalidArgumentException When the function name is not a plain identifier
     */
    public static function fromCall(string $func, mixed ...$args): self
    {
        return (new self())->call($func, ...$args);
    }

    /**
     * @return list<ExpressionPart>
     */
    public function getExpressions(): array
    {
        return $this->expressions;
    }

    public function column(string|self $value): static
    {
        return $this->addExpression(new ColumnPart($value));
    }

    public function op(string $value): static
    {
        return $this->addExpression(new OperatorPart($value));
    }

    public function value(mixed $value): static
    {
        return $this->addExpression(new ValuePart($value));
    }

    /**
     * @param Closure(Expression): mixed $closure
     */
    public function group(Closure $closure): static
    {
        return $this->addExpression(new GroupPart(self::fromClosure($closure)));
    }

    /**
     * @param string|array<int|string, string> $tables
     */
    public function from(string|array $tables): SelectStatement
    {
        $subquery = new Subquery();
        $this->addExpression(new SubqueryPart($subquery));

        return $subquery->from($tables);
    }

    /**
     * @param ColumnArg|list<ColumnArg> $column
     */
    public function count(string|self|Closure|array $column = '*', bool $distinct = false): static
    {
        $columns = is_array($column) ? array_map(self::normalize(...), $column) : [self::normalize($column)];
        if ($columns === []) {
            $columns = ['*'];
        }

        return $this->addExpression(
            new AggregateFunction(AggregateName::Count, $columns, $distinct || count($columns) > 1),
        );
    }

    /**
     * @param ColumnArg $column
     */
    public function sum(string|self|Closure $column, bool $distinct = false): static
    {
        return $this->aggregate(AggregateName::Sum, $column, $distinct);
    }

    /**
     * @param ColumnArg $column
     */
    public function avg(string|self|Closure $column, bool $distinct = false): static
    {
        return $this->aggregate(AggregateName::Avg, $column, $distinct);
    }

    /**
     * @param ColumnArg $column
     */
    public function max(string|self|Closure $column, bool $distinct = false): static
    {
        return $this->aggregate(AggregateName::Max, $column, $distinct);
    }

    /**
     * @param ColumnArg $column
     */
    public function min(string|self|Closure $column, bool $distinct = false): static
    {
        return $this->aggregate(AggregateName::Min, $column, $distinct);
    }

    /**
     * @param ColumnArg $column
     */
    public function ucase(string|self|Closure $column): static
    {
        return $this->addExpression(new SqlFunction(FunctionName::Ucase, self::normalize($column)));
    }

    /**
     * @param ColumnArg $column
     */
    public function lcase(string|self|Closure $column): static
    {
        return $this->addExpression(new SqlFunction(FunctionName::Lcase, self::normalize($column)));
    }

    /**
     * @param ColumnArg $column
     */
    public function mid(string|self|Closure $column, int $start = 1, int $length = 0): static
    {
        return $this->addExpression(
            new SqlFunction(FunctionName::Mid, self::normalize($column), start: $start, length: $length),
        );
    }

    /**
     * @param ColumnArg $column
     */
    public function len(string|self|Closure $column): static
    {
        return $this->addExpression(new SqlFunction(FunctionName::Len, self::normalize($column)));
    }

    /**
     * @param ColumnArg $column
     */
    public function round(string|self|Closure $column, int $decimals = 0): static
    {
        return $this->addExpression(
            new SqlFunction(FunctionName::Round, self::normalize($column), decimals: $decimals),
        );
    }

    /**
     * Current date and time: NOW() on MySQL/PostgreSQL, GETDATE() on SQL Server,
     * datetime('now') on SQLite (UTC there).
     */
    public function now(): static
    {
        return $this->addExpression(new SqlFunction(FunctionName::Now));
    }

    /**
     * Current date without time: CURRENT_DATE (SQL Server: CAST(GETDATE() AS DATE)).
     */
    public function currentDate(): static
    {
        return $this->addExpression(new SqlFunction(FunctionName::CurrentDate));
    }

    /**
     * `$date + $amount $unit`, e.g. `$e->dateAdd('created_at', 30, Interval::Day)`.
     *
     * @param ColumnArg $date A column name or an expression (`fn ($e) => $e->now()`)
     * @param int|Expression|(Closure(Expression): mixed) $amount A number (bound as a parameter) or an expression
     */
    public function dateAdd(string|self|Closure $date, int|self|Closure $amount, Interval $unit): static
    {
        return $this->addExpression(new DateArithmetic(self::normalize($date), self::amount($amount), $unit));
    }

    /**
     * `$date - $amount $unit`.
     *
     * @param ColumnArg $date A column name or an expression
     * @param int|Expression|(Closure(Expression): mixed) $amount A number (bound as a parameter) or an expression
     */
    public function dateSub(string|self|Closure $date, int|self|Closure $amount, Interval $unit): static
    {
        return $this->addExpression(new DateArithmetic(self::normalize($date), self::amount($amount), $unit, true));
    }

    /**
     * Now minus the interval: `->where('added_on')->atMost(fn ($e) => $e->ago(4, Interval::Day))`.
     *
     * @param int|Expression|(Closure(Expression): mixed) $amount
     */
    public function ago(int|self|Closure $amount, Interval $unit): static
    {
        return $this->dateSub((new self())->now(), $amount, $unit);
    }

    /**
     * Now plus the interval.
     *
     * @param int|Expression|(Closure(Expression): mixed) $amount
     */
    public function fromNow(int|self|Closure $amount, Interval $unit): static
    {
        return $this->dateAdd((new self())->now(), $amount, $unit);
    }

    /**
     * Sets and/or clears bits of an integer column, for `set()`:
     * `$update->set(['flags' => fn ($e) => $e->bits('flags', set: A, clear: B)])` gives
     * `("flags" & ~B) | A`. Bit 63 is PHP_INT_MIN.
     *
     * MySQL's bit operators return unsigned 64-bit values: for a signed BIGINT column holding
     * bit 63 (a negative number) pass `signed: true`, or the update fails with "out of range".
     *
     * @param ColumnArg $column
     */
    public function bits(string|self|Closure $column, int $set = 0, int $clear = 0, bool $signed = false): static
    {
        return $this->addExpression(new BitsPart(self::normalize($column), $set, $clear, $signed));
    }

    /**
     * The scalar at a JSON path as text (JSON null is NULL): `$e->json('meta', 'address.city')`,
     * also `'$.items[0]'` or `['address', 'city']`. Same as the `meta->address->city` column form.
     *
     * @param ColumnArg $column
     * @param string|list<string|int> $path
     *
     * @throws \InvalidArgumentException On an invalid key (quotes, backslashes, control characters)
     */
    public function json(string|self|Closure $column, string|array $path): static
    {
        return $this->addExpression(new JsonPart(self::normalize($column), JsonPath::fromPath($path)));
    }

    /**
     * INET6_ATON(value): IPv4/IPv6 text to VARBINARY(16). MySQL/MariaDB only.
     *
     * @param mixed $value A value (bound as a parameter) or an Expression / closure
     */
    public function inet6Aton(mixed $value): static
    {
        return $this->addExpression(new SqlFunction(FunctionName::Inet6Aton, self::valueArgument($value)));
    }

    /**
     * INET6_NTOA(column): VARBINARY(16) to text. MySQL/MariaDB only.
     *
     * @param ColumnArg $column
     */
    public function inet6Ntoa(string|self|Closure $column): static
    {
        return $this->addExpression(new SqlFunction(FunctionName::Inet6Ntoa, self::normalize($column)));
    }

    /**
     * INET_ATON(value): dotted IPv4 to an integer. MySQL/MariaDB only.
     *
     * @param mixed $value A value (bound as a parameter) or an Expression / closure
     */
    public function inetAton(mixed $value): static
    {
        return $this->addExpression(new SqlFunction(FunctionName::InetAton, self::valueArgument($value)));
    }

    /**
     * INET_NTOA(column): integer to dotted IPv4. MySQL/MariaDB only.
     *
     * @param ColumnArg $column
     */
    public function inetNtoa(string|self|Closure $column): static
    {
        return $this->addExpression(new SqlFunction(FunctionName::InetNtoa, self::normalize($column)));
    }

    /**
     * @param ColumnArg $column
     */
    public function format(string|self|Closure $column, mixed $format): static
    {
        return $this->addExpression(
            new SqlFunction(FunctionName::Format, self::normalize($column), format: $format),
        );
    }

    /**
     * Appends a call to an SQL function. Arguments that are expressions (or closures building one)
     * are inlined; anything else is bound as a parameter, so columns must be passed as
     * `fn (Expression $e) => $e->column('name')` or `Expression::fromColumn('name')`.
     *
     * @throws InvalidArgumentException When the function name is not a plain identifier
     */
    public function call(string $func, mixed ...$args): static
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/', $func) !== 1) {
            throw new InvalidArgumentException('Invalid SQL function name: ' . $func);
        }

        return $this->addExpression(new CallPart($func, array_map(self::argument(...), array_values($args))));
    }

    /**
     * Unknown methods are SQL function calls: `$expr->COALESCE(...)` is `$expr->call('COALESCE', ...)`.
     *
     * @param array<array-key, mixed> $arguments
     *
     * @throws InvalidArgumentException When the function name is not a plain identifier
     */
    public function __call(string $name, array $arguments): static
    {
        return $this->call($name, ...array_values($arguments));
    }

    /**
     * @throws LogicException Always: property writes would silently be lost
     */
    public function __set(string $name, mixed $value): void
    {
        throw new LogicException('Cannot set property "' . $name . '" on ' . self::class);
    }

    /**
     * Appends the property name as an operator, e.g. `$expr->{'*'}`.
     */
    public function __get(string $value): static
    {
        return $this->addExpression(new OperatorPart($value));
    }

    /**
     * @param ColumnArg $column
     */
    protected function aggregate(AggregateName $name, string|self|Closure $column, bool $distinct): static
    {
        return $this->addExpression(new AggregateFunction($name, [self::normalize($column)], $distinct));
    }

    protected function addExpression(ExpressionPart $part): static
    {
        $this->expressions[] = $part;

        return $this;
    }

    /**
     * @param int|Expression|(Closure(Expression): mixed) $amount
     */
    private static function amount(int|self|Closure $amount): int|self
    {
        if ($amount instanceof Closure) {
            /** @var Closure(Expression): mixed $amount */
            return self::fromClosure($amount);
        }

        return $amount;
    }

    /**
     * A function argument that is a value unless it is already an expression.
     */
    private static function valueArgument(mixed $value): self
    {
        if ($value instanceof Closure) {
            /** @var Closure(Expression): mixed $value */
            return self::fromClosure($value);
        }

        return $value instanceof self ? $value : (new self())->value($value);
    }

    /**
     * Closures become expressions; anything else is a value.
     */
    private static function argument(mixed $arg): mixed
    {
        if ($arg instanceof Closure) {
            /** @var Closure(Expression): mixed $arg */
            return self::fromClosure($arg);
        }

        return $arg;
    }

    /**
     * @param ColumnArg $column
     */
    private static function normalize(string|self|Closure $column): string|self
    {
        return $column instanceof Closure ? self::fromClosure($column) : $column;
    }
}
