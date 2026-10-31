<?php

namespace Ernestdefoe\Kiosk\Service;

use Carbon\Carbon;
use Ernestdefoe\Kiosk\Model\Ad;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Filesystem\Factory as Filesystem;
use Illuminate\Database\ConnectionInterface;

/**
 * The ads a visitor is sent, and their counts.
 *
 * 🚨 forActor() runs inside the forum payload, so on every page load: every
 * ad that could still run is cached as one list (forgotten whenever the
 * admin saves an ad or a product), and dates and audience are checked in
 * PHP. A page costs no query, with 1 ad or 50.
 */
class Ads
{
    private const KEY = 'kiosk.ads';

    /** At most this many ads per location reach the page; it rotates through them. */
    private const PER_LOCATION = 5;

    /** @var array{ads: list<array<string, mixed>>, offer: ?string}|null */
    private ?array $live = null;

    public function __construct(
        private ConnectionInterface $db,
        private Cache $cache,
        private Access $access,
        private Filesystem $files,
    ) {
    }

    /**
     * Members who hold "No ads" (bought, or through a group with the Ad-free
     * permission) see none. Admins always do, to check what members see.
     */
    public function adFree(User $actor): bool
    {
        if ($actor->isGuest() || $actor->isAdmin()) {
            return false;
        }

        return $this->access->has($actor, 'ads', 1) || $actor->hasPermission('kiosk.adFree');
    }

    /** @return list<array<string, mixed>> */
    public function forActor(User $actor): array
    {
        $live = $this->live();
        if (! $live['ads'] || $this->adFree($actor)) {
            return [];
        }

        $now = Carbon::now()->getTimestamp();
        $out = [];
        $per = [];

        foreach ($live['ads'] as $ad) {
            if (($ad['starts'] && $ad['starts'] > $now) || ($ad['ends'] && $ad['ends'] <= $now)
                || ($ad['audience'] === 'guests' && ! $actor->isGuest())
                || ($ad['audience'] === 'members' && $actor->isGuest())) {
                continue;
            }

            $per[$ad['location']] = ($per[$ad['location']] ?? 0) + 1;
            if ($per[$ad['location']] > self::PER_LOCATION) {
                continue;
            }

            unset($ad['starts'], $ad['ends'], $ad['audience']);
            $out[] = $ad;
        }

        return $out;
    }

    /** The store slug of a product that removes ads, for the "Remove ads" link; null when none is for sale. */
    public function offer(User $actor): ?string
    {
        return $actor->isAdmin() ? null : $this->live()['offer'];
    }

    /** Ids of the ads that can be running now, for checking what a browser reports. @return list<int> */
    public function liveIds(): array
    {
        return array_map(fn ($ad) => (int) $ad['id'], $this->live()['ads']);
    }

    /** @param array<int, int> $counts ad id => times seen */
    public function seen(array $counts): void
    {
        $this->add($counts, 'impressions');
    }

    public function clicked(int $adId): void
    {
        $this->add([$adId => 1], 'clicks');
    }

    public function forget(): void
    {
        $this->live = null;
        $this->cache->forget(self::KEY);
    }

    /** @return array{ads: list<array<string, mixed>>, offer: ?string} */
    private function live(): array
    {
        return $this->live ??= (array) $this->cache->remember(self::KEY, 3600, function () {
            $disk = $this->files->disk('flarum-assets');

            $ads = Ad::query()
                ->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', Carbon::now()))
                ->orderBy('id')
                ->get()
                ->map(fn (Ad $ad) => [
                    'id' => $ad->id,
                    'location' => $ad->location,
                    'kind' => $ad->kind,
                    'image' => $ad->kind === Ad::IMAGE && $ad->image_path ? $disk->url($ad->image_path) : null,
                    'width' => $ad->image_width,
                    'height' => $ad->image_height,
                    'alt' => $ad->alt_text,
                    'link' => $ad->kind === Ad::IMAGE ? $ad->link_url : null,
                    'html' => $ad->kind === Ad::HTML ? $ad->html : null,
                    'audience' => $ad->audience,
                    'starts' => $ad->starts_at?->getTimestamp(),
                    'ends' => $ad->ends_at?->getTimestamp(),
                ])
                ->filter(fn ($ad) => $ad['image'] !== null || $ad['html'] !== null)
                ->values()
                ->all();

            // The first product on sale that removes ads.
            $offer = $ads ? $this->db->table('kiosk_product_unlocks as u')
                ->join('kiosk_products as p', 'p.id', '=', 'u.product_id')
                ->where('u.type', 'ads')
                ->where('p.is_active', true)
                ->where('p.is_hidden', false)
                ->orderBy('p.position')
                ->value('p.slug') : null;

            return ['ads' => $ads, 'offer' => $offer];
        });
    }

    /** @param array<int, int> $counts */
    private function add(array $counts, string $column): void
    {
        $day = Carbon::now()->toDateString();

        foreach ($counts as $adId => $n) {
            $row = $this->db->table('kiosk_ad_stats')->where('ad_id', $adId)->where('day', $day);

            if (! (clone $row)->increment($column, $n)) {
                // The day's first count. A race inserts once; the other increments.
                $this->db->table('kiosk_ad_stats')->insertOrIgnore(['ad_id' => $adId, 'day' => $day, 'impressions' => 0, 'clicks' => 0]);
                $row->increment($column, $n);
            }
        }
    }
}
