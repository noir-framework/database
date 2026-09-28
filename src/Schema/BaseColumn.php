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

namespace Noirapi\Database\Schema;

use function in_array;
use function is_bool;
use function is_int;
use function is_string;
use function strtolower;

/**
 * A column definition: a name, an abstract type and a bag of properties
 * (size, nullable, default, unsigned, length, precision, autoincrement, description).
 */
class BaseColumn
{
    /** @var array<string, mixed> */
    protected array $properties = [];

    public function __construct(protected string $name, protected ?string $type = null)
    {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): string
    {
        return $this->type ?? '';
    }

    /**
     * @return array<string, mixed>
     */
    public function getProperties(): array
    {
        return $this->properties;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function set(string $name, mixed $value): static
    {
        $this->properties[$name] = $value;

        return $this;
    }

    public function has(string $name): bool
    {
        return isset($this->properties[$name]);
    }

    public function get(string $name, mixed $default = null): mixed
    {
        return $this->properties[$name] ?? $default;
    }

    /**
     * @param string $value tiny, small, normal, medium or big; anything else is ignored
     */
    public function size(string $value): static
    {
        $value = strtolower($value);
        if (!in_array($value, ['tiny', 'small', 'normal', 'medium', 'big'], true)) {
            return $this;
        }

        return $this->set('size', $value);
    }

    public function notNull(): static
    {
        return $this->set('nullable', false);
    }

    public function description(string $comment): static
    {
        return $this->set('description', $comment);
    }

    public function defaultValue(mixed $value): static
    {
        return $this->set('default', $value);
    }

    public function unsigned(bool $value = true): static
    {
        return $this->set('unsigned', $value);
    }

    public function length(?int $value): static
    {
        return $this->set('length', $value);
    }

    /**
     * @return 'tiny'|'small'|'normal'|'medium'|'big'
     */
    public function getSize(): string
    {
        return match ($this->properties['size'] ?? null) {
            'tiny' => 'tiny',
            'small' => 'small',
            'medium' => 'medium',
            'big' => 'big',
            default => 'normal',
        };
    }

    public function isNullable(): bool
    {
        return $this->bool('nullable', true);
    }

    public function isUnsigned(): bool
    {
        return $this->bool('unsigned', false);
    }

    public function isAutoincrement(): bool
    {
        return $this->bool('autoincrement', false);
    }

    public function getDefault(): mixed
    {
        return $this->properties['default'] ?? null;
    }

    public function getLength(): ?int
    {
        return $this->int('length');
    }

    public function getPrecision(): ?int
    {
        return $this->int('precision');
    }

    public function getDescription(): ?string
    {
        return isset($this->properties['description']) && is_string($this->properties['description'])
            ? $this->properties['description']
            : null;
    }

    private function bool(string $name, bool $default): bool
    {
        return isset($this->properties[$name]) && is_bool($this->properties[$name]) ? $this->properties[$name] : $default;
    }

    private function int(string $name): ?int
    {
        return isset($this->properties[$name]) && is_int($this->properties[$name]) ? $this->properties[$name] : null;
    }
}
