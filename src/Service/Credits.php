<?php

namespace Ernestdefoe\Kiosk\Service;

use Carbon\Carbon;
use Ernestdefoe\Kiosk\Model\Entitlement;
use Ernestdefoe\Kiosk\Model\Price;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;

/**
 * Paid actions in tags: posting, bumping, tipping.
 *
 * A purchase of a one-time price is ONE credit, spent by using it. A
 * fixed-term or renewing price for posting is a pass: unlimited posting
 * while it lasts. Which tags have something for sale is cached until a
 * product changes; what a member holds comes from Access.
 */
class Credits
{
    private const KEY = 'kiosk.credits';

    /** @var array<string, list<int>>|null kind => tag ids with an active product */
    private ?array $tags = null;

    public function __construct(private ConnectionInterface $db, private Cache $cache, private Access $access, private Gate $gate)
    {
    }

    /** @return array<string, list<int>> */
    public function tags(): array
    {
        return $this->tags ??= (array) $this->cache->remember(self::KEY, 3600, function () {
            $out = ['post' => [], 'bump' => [], 'tip' => []];
            foreach ($this->db->table('kiosk_product_unlocks')
                ->join('kiosk_products', 'kiosk_products.id', '=', 'kiosk_product_unlocks.product_id')
                ->whereIn('kiosk_product_unlocks.type', array_keys($out))
                ->where('kiosk_products.is_active', true)
                ->get(['kiosk_product_unlocks.type', 'kiosk_product_unlocks.target_id']) as $row) {
                $out[$row->type][] = (int) $row->target_id;
            }

            return array_map(fn ($ids) => array_values(array_unique($ids)), $out);
        });
    }

    public function forget(): void
    {
        $this->tags = null;
        $this->cache->forget(self::KEY);
    }

    /** Drop this process's memo only (a queue worker, between jobs). */
    public function flush(): void
    {
        $this->tags = null;
    }

    /** Tags where posting costs something and this person can't post right now. @return list<int> */
    public function postLockedTags(User $actor): array
    {
        if ($this->gate->bypasses($actor)) {
            return [];
        }

        return array_values(array_diff($this->tags()['post'], $this->access->ids($actor, 'post')));
    }

    /** How many bumps this person has for a discussion with these tags. @param list<int> $tagIds */
    public function bumpsFor(User $actor, array $tagIds): int
    {
        return count(array_filter($this->access->ids($actor, 'bump'), fn ($id) => in_array($id, $tagIds, true)));
    }

    /**
     * Spend one credit of this kind for one of these tags. Passes (no end, or
     * not from a one-time price) are not spent. Returns false when there is
     * nothing to spend.
     *
     * @param list<int> $tagIds
     */
    public function spend(User $actor, string $kind, array $tagIds): bool
    {
        $live = Entitlement::query()->live()
            ->where('kiosk_entitlements.user_id', $actor->id)
            ->where('kiosk_entitlements.type', $kind)
            ->whereIn('kiosk_entitlements.target_id', $tagIds)
            ->join('kiosk_purchases', 'kiosk_purchases.id', '=', 'kiosk_entitlements.purchase_id')
            ->orderBy('kiosk_entitlements.id')
            ->toBase()
            ->get(['kiosk_entitlements.id', 'kiosk_purchases.kind']);

        if ($live->isEmpty()) {
            return false;
        }

        // A pass covers it: nothing to spend.
        if ($kind === 'post' && $live->contains(fn ($e) => $e->kind !== Price::ONE_OFF)) {
            return true;
        }

        $credit = $live->first(fn ($e) => $e->kind === Price::ONE_OFF);
        if (! $credit) {
            return false;
        }

        $spent = Entitlement::query()->whereKey($credit->id)->whereNull('closed_at')
            ->update(['ends_at' => Carbon::now(), 'closed_at' => Carbon::now()]);
        $this->access->forget($actor->id);

        return (bool) $spent;
    }
}
