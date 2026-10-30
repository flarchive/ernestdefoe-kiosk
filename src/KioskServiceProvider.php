<?php

namespace Ernestdefoe\Kiosk;

use Ernestdefoe\Kiosk\Provider\Providers;
use Ernestdefoe\Kiosk\Service\Access;
use Ernestdefoe\Kiosk\Unlock\Registry;
use Flarum\Foundation\AbstractServiceProvider;
use Illuminate\Contracts\Container\Container;

class KioskServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->singleton('kiosk.unlock_types', fn () => []);
        $this->container->singleton('kiosk.providers', fn () => []);

        $this->container->singleton(Registry::class, fn (Container $c) => new Registry($c, $c->make('kiosk.unlock_types')));
        $this->container->singleton(Providers::class, fn (Container $c) => new Providers($c, $c->make('kiosk.providers')));

        // One per request lifecycle: its memo is what keeps permission checks
        // to one lookup per member.
        $this->container->singleton(Access::class);
        $this->container->singleton(Service\Gate::class);
        $this->container->singleton(Service\Credits::class);
    }

    public function boot(\Illuminate\Contracts\Events\Dispatcher $events): void
    {
        // 🚨 A queue worker runs for days: each job starts from what's sold
        // and who bought it now, not when the worker started.
        // Cleared in place: policies and listeners hold these same instances.
        $events->listen(\Illuminate\Queue\Events\JobProcessing::class, function (): void {
            foreach ([Access::class, Service\Gate::class, Service\Credits::class] as $memo) {
                if ($this->container->resolved($memo)) {
                    $this->container->make($memo)->flush();
                }
            }
        });
    }
}
