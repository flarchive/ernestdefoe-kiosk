<?php

namespace Ernestdefoe\Kiosk\Extend;

use Flarum\Extend\ExtenderInterface;
use Flarum\Extension\Extension;
use Illuminate\Contracts\Container\Container;

/**
 * Make something sellable through Kiosk:
 *
 *     (new \Ernestdefoe\Kiosk\Extend\UnlockType('syllabus-course', CourseUnlock::class))
 *
 * The class implements \Ernestdefoe\Kiosk\Unlock\UnlockType. Gate the item by
 * asking \Ernestdefoe\Kiosk\Service\Access::has($actor, 'syllabus-course', $id),
 * or ids() in a visibility scope. Put the extender inside
 * Extend\Conditional()->whenExtensionEnabled('ernestdefoe-kiosk', …) so your
 * extension still works on forums without Kiosk.
 */
class UnlockType implements ExtenderInterface
{
    /** @param class-string<\Ernestdefoe\Kiosk\Unlock\UnlockType> $class */
    public function __construct(private string $key, private string $class)
    {
    }

    public function extend(Container $container, ?Extension $extension = null): void
    {
        $container->extend('kiosk.unlock_types', fn (array $types) => [...$types, $this->key => $this->class]);
    }
}
