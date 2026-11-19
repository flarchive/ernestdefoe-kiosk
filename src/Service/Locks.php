<?php

namespace Ernestdefoe\Kiosk\Service;

use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * One at a time for a check-then-write, where the check counts rows the write
 * adds (checkouts in ten minutes, a coupon used once, seats left, listings
 * per seller). A cache lock, not lockForUpdate (a no-op on SQLite, and it
 * can't lock rows that don't exist yet). Never waits: a second request at the
 * same moment gets "busy, try again", so no PHP worker is held.
 */
class Locks
{
    public function __construct(private Cache $cache, private TranslatorInterface $translator)
    {
    }

    /**
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public function exclusive(string $key, \Closure $work): mixed
    {
        $store = $this->cache->getStore();

        // Every store Flarum ships (file, database, Redis, array) gives locks;
        // one that doesn't runs the work unlocked, as before.
        if (! $store instanceof LockProvider) {
            return $work();
        }

        $lock = $store->lock('kiosk.lock.'.$key, 30);
        if (! $lock->get()) {
            throw new ValidationException(['kiosk' => $this->translator->trans('ernestdefoe-kiosk.forum.errors.busy')]);
        }

        try {
            return $work();
        } finally {
            $lock->release();
        }
    }
}
