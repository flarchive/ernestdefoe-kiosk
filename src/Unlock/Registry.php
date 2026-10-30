<?php

namespace Ernestdefoe\Kiosk\Unlock;

use Illuminate\Contracts\Container\Container;

/** The unlock types registered on this forum, by key. */
class Registry
{
    /** @var array<string, UnlockType> */
    private array $resolved = [];

    /** @param array<string, class-string<UnlockType>> $types */
    public function __construct(private Container $container, private array $types)
    {
    }

    public function has(string $key): bool
    {
        return isset($this->types[$key]);
    }

    public function get(string $key): ?UnlockType
    {
        if (! isset($this->types[$key])) {
            return null;
        }

        return $this->resolved[$key] ??= $this->container->make($this->types[$key]);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->types);
    }
}
