<?php

namespace Ernestdefoe\Kiosk\Service;

use Ernestdefoe\Kiosk\Model\Entitlement;
use Ernestdefoe\Kiosk\Model\Price;
use Ernestdefoe\Kiosk\Model\Product;
use Ernestdefoe\Kiosk\Model\Purchase;
use Ernestdefoe\Kiosk\Unlock\Registry;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;

/**
 * Products, prices and purchases as the plain arrays the API sends.
 *
 * 🚨 Unlock names are looked up once per TYPE for the whole list (one
 * describe() call per type), never once per product or per item, so a store
 * of twenty products costs the same few queries as a store of one.
 */
class Presenter
{
    public function __construct(private Registry $types, private TranslatorInterface $translator, private ConnectionInterface $db)
    {
    }

    /**
     * @param iterable<Product> $products with unlocks and prices loaded
     * @return list<array<string, mixed>>
     */
    public function products(iterable $products, User $actor, bool $admin = false): array
    {
        $products = Collection::make($products);
        $names = $this->names($products->flatMap(fn (Product $p) => $p->unlocks->map(fn ($u) => [$u->type, $u->target_id])));
        $tagIds = $products->flatMap(fn (Product $p) => $p->unlocks->where('type', 'tag')->pluck('target_id'))->unique()->values()->all();
        $teasers = $tagIds ? resolve(\Illuminate\Database\ConnectionInterface::class)->table('kiosk_tag_groups')->whereIn('tag_id', $tagIds)->pluck('teaser', 'tag_id')->all() : [];

        return $products->map(fn (Product $p) => [
            'id' => $p->id,
            'name' => $p->name,
            'slug' => $p->slug,
            'description' => $p->description,
            'benefits' => $p->benefitLines(),
            'icon' => $p->icon,
            'color' => $p->color,
            'hidden' => $p->is_hidden,
            'unlocks' => $p->unlocks->map(fn ($u) => [
                'type' => $u->type,
                'targetId' => $u->target_id,
                'label' => $names[$u->type][$u->target_id]['label'] ?? null,
                'url' => $names[$u->type][$u->target_id]['url'] ?? null,
                'missing' => ! isset($names[$u->type][$u->target_id]),
                'teaser' => $u->type === 'tag' ? (bool) ($teasers[$u->target_id] ?? true) : null,
            ])->values()->all(),
            'prices' => $p->prices->map(fn (Price $price) => $this->price($price))->values()->all(),
            'refusal' => $p->refusal($actor),
        ] + ($admin ? [
            'active' => $p->is_active,
            'buyerGroupIds' => $p->buyerGroupIds(),
            'stock' => $p->stock,
            'soldCount' => $p->sold_count,
            'availableFrom' => $p->available_from?->toIso8601String(),
            'availableUntil' => $p->available_until?->toIso8601String(),
            'benefitsText' => (string) $p->benefits,
        ] : []))->values()->all();
    }

    /** @return array<string, mixed> */
    public function price(Price $price): array
    {
        return [
            'id' => $price->id,
            'amountCents' => $price->amount_cents,
            'currency' => strtoupper($price->currency),
            'kind' => $price->kind,
            'interval' => $price->interval,
            'intervalCount' => $price->interval_count,
            'termDays' => $price->term_days,
            'trialDays' => $price->trial_days,
            'seats' => max(1, (int) $price->seats),
        ];
    }

    /**
     * @param iterable<Purchase> $purchases with entitlements loaded
     * @return list<array<string, mixed>>
     */
    public function purchases(iterable $purchases, bool $admin = false, ?User $viewer = null): array
    {
        $purchases = Collection::make($purchases);

        // Seat holders, and the people on either side of a gift: two queries
        // for the whole list.
        $seats = $purchases->contains(fn (Purchase $p) => $p->seats > 1)
            ? $this->db->table('kiosk_seats')->whereIn('purchase_id', $purchases->pluck('id'))->orderBy('added_at')->get()->groupBy('purchase_id')
            : Collection::make();
        $ids = $seats->flatten()->pluck('user_id')
            ->merge($purchases->pluck('gifted_by')->filter())
            ->merge($purchases->filter(fn (Purchase $p) => $p->gifted_by || ($viewer && $p->user_id !== (int) $viewer->id))->pluck('user_id'))->unique()->values();
        $people = $ids->isEmpty() ? Collection::make() : User::query()->whereIn('id', $ids)->get()->keyBy('id'); // display_name is worked out (nicknames), not a column
        $person = fn ($id) => ($u = $people->get($id)) ? ['id' => (int) $u->id, 'username' => (string) $u->username, 'displayName' => (string) $u->display_name] : null;
        $names = $this->names($purchases->flatMap(fn (Purchase $p) => $p->entitlements->map(fn (Entitlement $e) => [$e->type, $e->target_id])));

        // A seat holder sees what they have, not what the owner paid.
        $private = fn (Purchase $p) => $viewer && $p->user_id !== (int) $viewer->id && $p->gifted_by !== (int) $viewer->id;

        return $purchases->map(fn (Purchase $p) => [
            'id' => $p->id,
            'priceId' => $p->price_id,
            'role' => ! $viewer || $p->user_id === (int) $viewer->id ? 'owner' : ($p->gifted_by === (int) $viewer->id ? 'giver' : 'seat'),
            'giftedBy' => $p->gifted_by ? $person($p->gifted_by) : null,
            'giftedTo' => $p->gifted_by ? $person($p->user_id) : null,
            'owner' => $viewer && $p->user_id !== (int) $viewer->id ? $person($p->user_id) : null,
            'couponCode' => $private($p) ? null : $p->coupon_code,
            'seats' => $p->seats,
            'seatHolders' => $seats->get($p->id, Collection::make())->map(fn ($s) => $person((int) $s->user_id))->filter()->values()->all(),
            'number' => $p->number,
            'productId' => $p->product_id,
            'productName' => $p->product_name,
            'amountCents' => $private($p) ? null : $p->amount_cents,
            'currency' => $p->currency,
            'kind' => $p->kind,
            'interval' => $p->interval,
            'intervalCount' => $p->interval_count,
            'termDays' => $p->term_days,
            'provider' => $p->provider,
            'mode' => $p->mode,
            'status' => $p->status,
            'live' => $p->isLive(),
            'periodStart' => $p->period_start?->toIso8601String(),
            'periodEnd' => $p->period_end?->toIso8601String(),
            'cancelAtPeriodEnd' => $p->cancel_at_period_end,
            'createdAt' => $p->created_at?->toIso8601String(),
            'paidAt' => $p->paid_at?->toIso8601String(),
            'canCancel' => $p->isRecurring() && $p->status === Purchase::ACTIVE && $p->provider !== Purchase::GRANT,
            'unlocks' => $p->entitlements->where('user_id', $p->user_id)->map(fn (Entitlement $e) => [
                'type' => $e->type,
                'targetId' => $e->target_id,
                'label' => $names[$e->type][$e->target_id]['label'] ?? null,
                'url' => $names[$e->type][$e->target_id]['url'] ?? null,
            ])->values()->all(),
            'payments' => $p->relationLoaded('payments') && ! $private($p) ? $p->payments->map(fn ($pay) => [
                'id' => $pay->id,
                'amountCents' => $pay->amount_cents,
                'currency' => $pay->currency,
                'status' => $pay->status,
                'refundedCents' => $pay->refunded_cents,
                'createdAt' => $pay->created_at?->toIso8601String(),
            ])->values()->all() : [],
        ] + ($admin ? [
            'note' => $p->note,
            'providerRef' => $p->provider_ref,
            'user' => $p->user ? [
                'id' => $p->user->id,
                'username' => $p->user->username,
                'displayName' => $p->user->display_name,
                'avatarUrl' => $p->user->avatar_url,
                'email' => $p->user->email,
            ] : null,
        ] : []))->values()->all();
    }

    /**
     * Labels and links for every (type, id) pair, one describe() per type.
     *
     * @param Collection<int, array{0: string, 1: int}> $pairs
     * @return array<string, array<int, array{label: string, url: ?string}>>
     */
    private function names(Collection $pairs): array
    {
        $out = [];

        foreach ($pairs->groupBy(fn (array $pair) => $pair[0]) as $type => $group) {
            $type = (string) $type;
            $ids = $group->map(fn ($p) => (int) $p[1])->unique()->values()->all();
            $out[$type] = $this->types->get($type)?->describe($ids) ?? [];
        }

        return $out;
    }

    /** @return list<array{key: string, label: string, icon: string}> */
    public function unlockTypes(): array
    {
        return array_map(fn (string $key) => [
            'key' => $key,
            'label' => (string) $this->translator->trans((string) $this->types->get($key)?->label()),
            'icon' => (string) $this->types->get($key)?->icon(),
        ], $this->types->keys());
    }
}
