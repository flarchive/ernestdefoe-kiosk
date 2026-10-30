<?php

namespace Ernestdefoe\Kiosk\Service;

use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;

/**
 * Paid tags a visitor cannot see yet, so the forum can show them locked with
 * a Buy button instead of letting them vanish.
 *
 * 🚨 Read on every page (it is a forum attribute), so it costs no query: the
 * list of paid tags is cached until a product changes, and "can they see it"
 * is answered from the permissions Flarum already loaded for the request.
 */
class PaidTags
{
    private const KEY = 'kiosk.paid_tags';

    public function __construct(private ConnectionInterface $db, private Cache $cache)
    {
    }

    /** @return list<array{id: int, name: string, slug: string, description: ?string, color: ?string, icon: ?string}> */
    public function lockedFor(User $actor): array
    {
        if ($actor->isAdmin()) {
            return [];
        }

        return array_values(array_filter($this->all(), fn (array $tag) => ! $actor->hasPermission('tag'.$tag['id'].'.viewForum')));
    }

    /** @return list<array{id: int, name: string, slug: string, description: ?string, color: ?string, icon: ?string}> */
    public function all(): array
    {
        return (array) $this->cache->remember(self::KEY, 3600, fn () => $this->db->table('tags')
            // Hidden-mode tags only: a teaser tag is visible anyway.
            ->whereIn('id', fn ($q) => $q->select('tag_id')->from('kiosk_tag_groups')->where('teaser', false))
            ->whereIn('id', fn ($q) => $q->select('kiosk_product_unlocks.target_id')
                ->from('kiosk_product_unlocks')
                ->join('kiosk_products', 'kiosk_products.id', '=', 'kiosk_product_unlocks.product_id')
                ->where('kiosk_product_unlocks.type', 'tag')
                ->where('kiosk_products.is_active', true))
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'description', 'color', 'icon'])
            ->map(fn ($t) => [
                'id' => (int) $t->id,
                'name' => (string) $t->name,
                'slug' => (string) $t->slug,
                'description' => $t->description,
                'color' => $t->color,
                'icon' => $t->icon,
            ])->all());
    }

    public function forget(): void
    {
        $this->cache->forget(self::KEY);
    }
}
