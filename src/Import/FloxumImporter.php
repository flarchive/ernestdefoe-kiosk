<?php

namespace Ernestdefoe\Kiosk\Import;

use Carbon\Carbon;
use Ernestdefoe\Kiosk\Model\Entitlement;
use Ernestdefoe\Kiosk\Model\Payment;
use Ernestdefoe\Kiosk\Model\Price;
use Ernestdefoe\Kiosk\Model\Product;
use Ernestdefoe\Kiosk\Model\Purchase;
use Ernestdefoe\Kiosk\Provider\StripeClient;
use Ernestdefoe\Kiosk\Service\Access;
use Ernestdefoe\Kiosk\Service\Fulfilment;
use Ernestdefoe\Kiosk\Service\ProductSaver;
use Flarum\Foundation\ValidationException;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Builder;

/**
 * Moves a forum from floxum/payments to Kiosk without anybody paying twice.
 *
 * floxum/payments keeps no ledger: Stripe product ids sit on groups and tags,
 * and Stripe holds the rest. So the importer reads those ids, asks Stripe for
 * each product's prices, and makes a Kiosk product per paid group and paid
 * tag feature, tied to the SAME Stripe prices. Then it adopts every live
 * Stripe subscription on those prices as a Kiosk purchase (Stripe keeps
 * billing it; Kiosk's webhook now follows it), keeps past pay-to-view buyers
 * reading what they paid for, and carries bounties over as tips.
 *
 * Safe to run again: anything already imported is skipped.
 */
class FloxumImporter
{
    /** floxum column => [Kiosk unlock type, what it is called] */
    private const TAG_FEATURES = [
        'blomstra_ptv_stripe_product_id' => ['tag', 'view'],
        'blomstra_ptp_stripe_product_id' => ['post', 'post'],
        'blomstra_ptb_stripe_product_id' => ['bump', 'bump'],
        'blomstra_bounty_stripe_product_id' => ['tip', 'tip'],
    ];

    /** @var array<string, int> */
    public array $counts = ['products' => 0, 'subscriptions' => 0, 'readers' => 0, 'tips' => 0, 'skipped' => 0];

    /** @var list<string> */
    public array $notes = [];

    private bool $dry = false;

    public function __construct(
        private ConnectionInterface $db,
        private StripeClient $stripe,
        private ProductSaver $saver,
        private Fulfilment $fulfilment,
        private Access $access,
    ) {
    }

    /** Whether floxum/payments' columns are here to read. */
    public function found(): bool
    {
        return $this->schema()->hasColumn('groups', 'blomstra_pta_stripe_product_id');
    }

    /** @return array<string, int> */
    public function run(bool $dry = false): array
    {
        $this->dry = $dry;

        /** @var array<string, Product> Stripe product id => Kiosk product */
        $made = [];

        foreach ($this->db->table('groups')->whereNotNull('blomstra_pta_stripe_product_id')->get(['id', 'name_plural', 'blomstra_pta_stripe_product_id']) as $group) {
            $made[$group->blomstra_pta_stripe_product_id] = $this->product((string) $group->blomstra_pta_stripe_product_id, (string) $group->name_plural, [['type' => 'group', 'targetId' => (int) $group->id]]);
        }

        $tags = $this->db->table('tags')->get();
        foreach (self::TAG_FEATURES as $column => [$type, $label]) {
            if (! $this->schema()->hasColumn('tags', $column)) {
                continue;
            }
            foreach ($tags->whereNotNull($column) as $tag) {
                $made[$tag->{$column}.'|'.$type] = $this->product((string) $tag->{$column}, $tag->name.' — '.$label, [['type' => $type, 'targetId' => (int) $tag->id, 'teaser' => true]]);
            }
        }

        $made = array_filter($made);

        foreach ($made as $product) {
            foreach ($product->prices->filter(fn (Price $p) => $p->isRecurring()) as $price) {
                $this->adoptSubscriptions($product, $price);
            }
        }

        $this->keepReaders();
        $this->carryBounties($made);

        return $this->counts;
    }

    /**
     * A Kiosk product for one Stripe product, with its active prices tied to
     * the same Stripe prices. The one already made, if this ran before.
     *
     * @param list<array<string, mixed>> $unlocks
     */
    private function product(string $stripeProduct, string $name, array $unlocks): ?Product
    {
        $prices = $this->list('/v1/prices', ['product' => $stripeProduct, 'active' => 'true']);
        $known = $prices ? $this->db->table('kiosk_price_refs')->where('provider', 'stripe')->whereIn('external_id', array_column($prices, 'id'))->value('price_id') : null;

        if ($known) {
            $this->counts['skipped']++;

            return Price::query()->find($known)?->product()->with('prices')->first();
        }

        $rows = [];
        $ids = [];
        foreach ($prices as $p) {
            $row = $this->price($p, $unlocks[0]['type'] === 'tip');
            if ($row) {
                $rows[] = $row;
                $ids[] = (string) $p['id'];
            }
        }

        if (! $rows) {
            $this->notes[] = "Stripe product $stripeProduct has no price Kiosk can sell; skipped \"$name\".";

            return null;
        }

        $this->counts['products']++;

        if ($this->dry) {
            return null;
        }

        try {
            $product = $this->saver->save(null, ['name' => mb_substr($name, 0, 150), 'unlocks' => $unlocks, 'prices' => $rows]);
        } catch (ValidationException $e) {
            // e.g. a billing interval longer than Kiosk sells: say so, carry on.
            $this->counts['products']--;
            $this->notes[] = "\"$name\" wasn't imported: ".implode(' ', $e->getAttributes());

            return null;
        }

        foreach ($product->prices->sortBy('position')->values() as $i => $price) {
            $this->db->table('kiosk_price_refs')->insertOrIgnore(['price_id' => $price->id, 'provider' => 'stripe', 'mode' => $this->stripe->mode(), 'external_id' => $ids[$i]]);
        }

        return $product;
    }

    /**
     * A Stripe price as Kiosk's admin API takes it, or null for what Kiosk
     * doesn't sell (metered or tiered billing).
     *
     * @param array<string, mixed> $p
     * @return array<string, mixed>|null
     */
    private function price(array $p, bool $tip): ?array
    {
        $currency = strtoupper((string) ($p['currency'] ?? 'usd'));

        if (! empty($p['custom_unit_amount']) || $tip) {
            return ['kind' => Price::CUSTOM, 'amountCents' => max(1, (int) ($p['custom_unit_amount']['minimum'] ?? $p['unit_amount'] ?? 100)), 'currency' => $currency];
        }
        if (! isset($p['unit_amount']) || ($p['billing_scheme'] ?? 'per_unit') !== 'per_unit' || (int) $p['unit_amount'] < 1) {
            return null;
        }
        if (($p['type'] ?? '') === 'recurring') {
            if (($p['recurring']['usage_type'] ?? 'licensed') !== 'licensed') {
                return null;
            }

            return ['kind' => Price::RECURRING, 'amountCents' => (int) $p['unit_amount'], 'currency' => $currency, 'interval' => $p['recurring']['interval'] ?? 'month', 'intervalCount' => (int) ($p['recurring']['interval_count'] ?? 1)];
        }

        return ['kind' => Price::ONE_OFF, 'amountCents' => (int) $p['unit_amount'], 'currency' => $currency];
    }

    /**
     * Every live subscription on this price becomes a Kiosk purchase Stripe
     * goes on billing. No receipt is sent: nothing was bought today.
     */
    private function adoptSubscriptions(Product $product, Price $price): void
    {
        $stripePrice = $this->db->table('kiosk_price_refs')->where(['price_id' => $price->id, 'provider' => 'stripe'])->value('external_id');

        foreach ($stripePrice ? $this->list('/v1/subscriptions', ['price' => $stripePrice, 'status' => 'all']) : [] as $sub) {
            if (! in_array($sub['status'] ?? '', ['active', 'trialing', 'past_due'], true)
                || Purchase::query()->where('provider', 'stripe')->where('provider_ref', $sub['id'])->exists()) {
                continue;
            }

            $user = $this->userFor($sub);
            if (! $user) {
                $this->notes[] = "Subscription {$sub['id']} belongs to no member here; left alone in Stripe.";
                continue;
            }

            $this->counts['subscriptions']++;
            if ($this->dry) {
                continue;
            }

            $purchase = Purchase::start($user, $price, $product, 'stripe', $this->stripe->mode());
            $purchase->provider_ref = (string) $sub['id'];
            $purchase->status = Purchase::ACTIVE;
            $purchase->period_start = Carbon::createFromTimestamp((int) ($sub['current_period_start'] ?? time()));
            $purchase->period_end = Carbon::createFromTimestamp((int) ($sub['current_period_end'] ?? time()));
            $purchase->cancel_at_period_end = ! empty($sub['cancel_at_period_end']);
            $purchase->paid_at = $purchase->period_start;
            $purchase->note = 'Imported from floxum/payments';
            $purchase->save();

            $this->fulfilment->open($purchase);
            Product::query()->whereKey($product->id)->increment('sold_count');

            // So Stripe's next events name the purchase as well as the subscription.
            $this->stripe->call('POST', '/v1/subscriptions/'.rawurlencode((string) $sub['id']), ['metadata' => ['kiosk_number' => $purchase->number]], 'kiosk-adopt-'.$sub['id']);
        }
    }

    /**
     * The member a floxum subscription was for: its metadata, or the Stripe
     * customer floxum saved on the user.
     *
     * @param array<string, mixed> $sub
     */
    private function userFor(array $sub): ?User
    {
        $id = (int) ($sub['metadata']['flarum-user-id'] ?? 0);
        $user = $id ? User::query()->find($id) : null;

        if (! $user && ! empty($sub['customer']) && $this->schema()->hasColumn('users', 'blomstra_stripe_customer_id')) {
            $user = User::query()->where('blomstra_stripe_customer_id', is_array($sub['customer']) ? $sub['customer']['id'] : $sub['customer'])->first();
        }

        return $user;
    }

    /**
     * floxum sold pay-to-view per discussion. Kiosk sells the tag, so each
     * earlier buyer keeps the discussions they paid for as a grant.
     */
    private function keepReaders(): void
    {
        if (! $this->schema()->hasColumn('discussion_user', 'blomstra_ptv_stripe_payment_id')) {
            return;
        }

        $rows = $this->db->table('discussion_user')->whereNotNull('blomstra_ptv_stripe_payment_id')->get(['user_id', 'discussion_id', 'blomstra_ptv_stripe_payment_id']);

        foreach ($rows->groupBy('user_id') as $userId => $paid) {
            $have = Entitlement::query()->where('user_id', $userId)->where('type', 'discussion')->pluck('target_id')->all();
            $new = $paid->reject(fn ($r) => in_array((int) $r->discussion_id, $have, true));

            if ($new->isEmpty() || ! User::query()->whereKey($userId)->exists()) {
                continue;
            }

            $this->counts['readers']++;
            if ($this->dry) {
                continue;
            }

            $purchase = new Purchase();
            $purchase->number = Purchase::newNumber();
            $purchase->user_id = (int) $userId;
            $purchase->product_name = 'Discussions bought earlier';
            $purchase->amount_cents = 0;
            $purchase->currency = 'USD';
            $purchase->kind = Price::ONE_OFF;
            $purchase->provider = Purchase::GRANT;
            $purchase->status = Purchase::ACTIVE;
            $purchase->paid_at = Carbon::now();
            $purchase->period_start = Carbon::now();
            $purchase->note = 'Imported from floxum/payments';
            $purchase->save();

            foreach ($new as $row) {
                $e = new Entitlement();
                $e->purchase_id = $purchase->id;
                $e->user_id = (int) $userId;
                $e->type = 'discussion';
                $e->target_id = (int) $row->discussion_id;
                $e->starts_at = Carbon::now();
                $e->save();
            }

            $this->access->forget((int) $userId);
        }
    }

    /**
     * floxum's bounties become tips on the discussion they backed, so its
     * total carries over. Needs the tag's tip product made above.
     *
     * @param array<string, Product> $made
     */
    private function carryBounties(array $made): void
    {
        if (! $this->schema()->hasTable('blomstra_payments_bounties')) {
            return;
        }

        $tipProducts = array_filter($made, fn ($k) => str_ends_with($k, '|tip'), ARRAY_FILTER_USE_KEY);

        foreach ($this->db->table('blomstra_payments_bounties')->orderBy('id')->get() as $bounty) {
            if (Payment::query()->where('provider', 'stripe')->where('provider_payment_id', $bounty->stripe_id)->exists()) {
                continue;
            }

            $tags = $this->db->table('discussion_tag')->where('discussion_id', $bounty->discussion_id)->pluck('tag_id')->map(fn ($v) => (int) $v)->all();
            $product = collect($tipProducts)->first(fn (Product $p) => $p->unlocks->contains(fn ($u) => in_array((int) $u->target_id, $tags, true)));

            if (! $product && ! $this->dry) {
                $this->notes[] = "Bounty {$bounty->stripe_id} is on a discussion with no tip product; not carried over.";
                continue;
            }

            $this->counts['tips']++;
            if ($this->dry) {
                continue;
            }

            $purchase = new Purchase();
            $purchase->number = Purchase::newNumber();
            $purchase->user_id = (int) $bounty->user_id;
            $purchase->product_id = $product->id;
            $purchase->product_name = $product->name;
            $purchase->amount_cents = (int) $bounty->amount;
            $purchase->currency = strtoupper((string) $bounty->currency);
            $purchase->kind = Price::CUSTOM;
            $purchase->provider = 'stripe';
            $purchase->mode = $this->stripe->mode();
            $purchase->provider_ref = (string) $bounty->stripe_id;
            $purchase->status = Purchase::ACTIVE;
            $purchase->context_type = 'discussion';
            $purchase->context_id = (int) $bounty->discussion_id;
            $purchase->paid_at = Carbon::parse($bounty->created_at);
            $purchase->period_start = $purchase->paid_at;
            $purchase->note = 'Imported from floxum/payments';
            $purchase->save();

            $this->fulfilment->recordPayment($purchase, (string) $bounty->stripe_id, (int) $bounty->amount, $purchase->currency);
        }
    }

    /**
     * Every page of a Stripe list.
     *
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    private function list(string $path, array $params): array
    {
        $out = [];
        $after = null;

        for ($page = 0; $page < 100; $page++) {
            $body = $this->stripe->call('GET', $path, $params + ['limit' => 100] + ($after ? ['starting_after' => $after] : []));
            $data = $body['data'] ?? [];
            array_push($out, ...$data);

            if (empty($body['has_more']) || ! $data) {
                break;
            }
            $after = end($data)['id'] ?? null;
        }

        return $out;
    }

    private function schema(): Builder
    {
        return $this->db->getSchemaBuilder();
    }
}
