<?php

namespace Ernestdefoe\Kiosk\Provider;

use Illuminate\Contracts\Container\Container;

/** The payment providers this forum can use, by key. */
class Providers
{
    /** @var array<string, Provider> */
    private array $resolved = [];

    /** @param array<string, class-string<Provider>> $providers */
    public function __construct(private Container $container, private array $providers)
    {
    }

    public function get(string $key): ?Provider
    {
        if (! isset($this->providers[$key])) {
            return null;
        }

        return $this->resolved[$key] ??= $this->container->make($this->providers[$key]);
    }

    /** @return list<Provider> those ready to take money now */
    public function ready(): array
    {
        $out = [];
        foreach (array_keys($this->providers) as $key) {
            $provider = $this->get($key);
            if ($provider && $provider->isReady()) {
                $out[] = $provider;
            }
        }

        return $out;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->providers);
    }
}
