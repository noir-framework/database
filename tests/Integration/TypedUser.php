<?php

declare(strict_types=1);

namespace Noirapi\Database\Test\Integration;

use DateTimeImmutable;

final class TypedUser
{
    public int $id;

    public string $name;

    /** @var array<string, mixed>|null */
    public ?array $meta;

    public bool $vip;

    public ?DateTimeImmutable $seen;

    public string $label = '';

    public function __construct(string $prefix = '')
    {
        // runs after the properties are set, like PDO::FETCH_CLASS
        $this->label = $prefix . $this->name;
    }
}
