<?php

namespace Ernestdefoe\Kiosk\Extend;

use Flarum\Extend\ExtenderInterface;
use Flarum\Extension\Extension;
use Illuminate\Contracts\Container\Container;

/** Add a way to be paid: a class implementing \Ernestdefoe\Kiosk\Provider\Provider. */
class Provider implements ExtenderInterface
{
    /** @param class-string<\Ernestdefoe\Kiosk\Provider\Provider> $class */
    public function __construct(private string $key, private string $class)
    {
    }

    public function extend(Container $container, ?Extension $extension = null): void
    {
        $container->extend('kiosk.providers', fn (array $providers) => [...$providers, $this->key => $this->class]);
    }
}
