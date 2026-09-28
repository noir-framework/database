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
use DateTimeImmutable;
use InvalidArgumentException;
use ReflectionClass;

use function array_key_exists;
use function in_array;
use function is_scalar;
use function is_string;
use function json_decode;

/**
 * The column conversions of ResultSet::cast().
 *
 * @internal
 */
final readonly class RowCaster
{
    private const array CASTS = ['int', 'float', 'bool', 'string', 'json', 'datetime'];

    /**
     * @param array<string, string|Closure(mixed): mixed> $casts column => cast
     *
     * @throws InvalidArgumentException On an unknown cast name
     */
    public function __construct(private array $casts)
    {
        foreach ($casts as $column => $cast) {
            if (is_string($cast) && !in_array($cast, self::CASTS, true)) {
                throw new InvalidArgumentException('Unknown cast "' . $cast . '" for column ' . $column);
            }
        }
    }

    /**
     * @param array<array-key, mixed> $row
     *
     * @return array<array-key, mixed>
     */
    public function castArray(array $row): array
    {
        foreach ($this->casts as $column => $cast) {
            if (array_key_exists($column, $row)) {
                /** @psalm-suppress MixedAssignment the converted value is mixed by design */
                $row[$column] = self::convert($row[$column], $cast);
            }
        }

        return $row;
    }

    /**
     * Builds the object the way PDO::FETCH_CLASS does: properties first, then the constructor.
     *
     * @param class-string $class
     * @param array<array-key, mixed> $row
     * @param list<mixed> $ctorArgs
     */
    public function hydrate(string $class, array $row, array $ctorArgs): mixed
    {
        $reflection = new ReflectionClass($class);
        $object = $reflection->newInstanceWithoutConstructor();
        /** @var mixed $value */
        foreach ($row as $column => $value) {
            if ($reflection->hasProperty((string) $column)) {
                $reflection->getProperty((string) $column)->setValue($object, $value);
            }
        }

        $reflection->getConstructor()?->invokeArgs($object, $ctorArgs);

        return $object;
    }

    /**
     * @param string|Closure(mixed): mixed $cast
     */
    private static function convert(mixed $value, string|Closure $cast): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($cast instanceof Closure) {
            return $cast($value);
        }

        $scalar = is_scalar($value) ? $value : '';

        return match ($cast) {
            'int' => (int) $scalar,
            'float' => (float) $scalar,
            'bool' => (bool) $scalar,
            'string' => (string) $scalar,
            'json' => json_decode((string) $scalar, true, 512, JSON_THROW_ON_ERROR),
            default => new DateTimeImmutable((string) $scalar),
        };
    }
}
