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

use InvalidArgumentException;

use function array_map;
use function array_slice;
use function explode;
use function implode;
use function is_array;
use function is_int;
use function preg_match;
use function preg_match_all;
use function str_contains;
use function str_starts_with;
use function substr;

/**
 * A path inside a JSON document: object keys and array indexes.
 *
 * Written as `meta->address->city` / `meta->items[0]` in column names, or as `address.city` /
 * `$.items[0]` / `['address', 'city']` for Expression::json(). Keys are inlined into the SQL,
 * so quotes, backslashes and control characters are rejected.
 */
final readonly class JsonPath
{
    /**
     * @param list<string|int> $segments
     */
    private function __construct(public array $segments)
    {
    }

    /**
     * Splits `column->key->key[0]` into the column and the path; null when there is no arrow.
     *
     * @return array{string, self}|null
     *
     * @throws InvalidArgumentException On an invalid key or index
     */
    public static function fromArrow(string $value): ?array
    {
        if (!str_contains($value, '->')) {
            return null;
        }

        $parts = explode('->', $value);
        $column = $parts[0];
        $segments = [];
        foreach (array_slice($parts, 1) as $part) {
            $segments = [...$segments, ...self::parseSegment($part)];
        }

        if ($column === '' || $segments === []) {
            throw new InvalidArgumentException('Invalid JSON column path: ' . $value);
        }

        return [$column, new self($segments)];
    }

    /**
     * @param string|list<string|int> $path `a.b[0]`, `$.a.b[0]` or a list of keys and indexes
     *
     * @throws InvalidArgumentException On an invalid key or index
     */
    public static function fromPath(string|array $path): self
    {
        if (is_array($path)) {
            foreach ($path as $segment) {
                if (!is_int($segment)) {
                    self::assertKey($segment);
                }
            }

            return $path === [] ? throw new InvalidArgumentException('Empty JSON path') : new self($path);
        }

        if (str_starts_with($path, '$')) {
            $path = substr($path, str_starts_with($path, '$.') ? 2 : 1);
        }

        $segments = [];
        foreach (explode('.', $path) as $part) {
            $segments = [...$segments, ...self::parseSegment($part)];
        }

        return $segments === [] ? throw new InvalidArgumentException('Empty JSON path') : new self($segments);
    }

    /**
     * `$."a"."b"[0]` for MySQL, SQLite and SQL Server.
     */
    public function dollar(): string
    {
        $path = '$';
        foreach ($this->segments as $segment) {
            $path .= is_int($segment) ? '[' . $segment . ']' : '."' . $segment . '"';
        }

        return $path;
    }

    /**
     * `{"a","b","0"}`: a PostgreSQL text[] literal for #>, #>> and jsonb_set().
     */
    public function pgArray(): string
    {
        $quoted = array_map(static fn (string|int $segment): string => '"' . $segment . '"', $this->segments);

        return '{' . implode(',', $quoted) . '}';
    }

    /**
     * `key[1][2]` -> ['key', 1, 2]; `[3]` -> [3].
     *
     * @return list<string|int>
     *
     * @throws InvalidArgumentException
     */
    private static function parseSegment(string $part): array
    {
        if (preg_match('/^([^\[\]]*)((?:\[\d+\])*)$/', $part, $match) !== 1 || ($match[1] === '' && $match[2] === '')) {
            throw new InvalidArgumentException('Invalid JSON path segment: ' . $part);
        }

        $segments = [];
        if ($match[1] !== '') {
            self::assertKey($match[1]);
            $segments[] = $match[1];
        }

        preg_match_all('/\[(\d+)\]/', $part, $indexes);
        foreach ($indexes[1] as $index) {
            $segments[] = (int) $index;
        }

        return $segments;
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function assertKey(string $key): void
    {
        if ($key === '' || preg_match('/["\'\\\\\x00-\x1f]/', $key) === 1) {
            throw new InvalidArgumentException('Invalid JSON key: ' . $key);
        }
    }
}
