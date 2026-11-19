<?php

namespace Ernestdefoe\Kiosk\Service;

use Carbon\Carbon;
use Ernestdefoe\Kiosk\Model\Money;
use Ernestdefoe\Kiosk\Model\Price;
use Ernestdefoe\Kiosk\Model\Product;
use Ernestdefoe\Kiosk\Model\ProductUnlock;
use Ernestdefoe\Kiosk\Model\Purchase;
use Ernestdefoe\Kiosk\Unlock\Registry;
use Ernestdefoe\Kiosk\Unlock\TagUnlock;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/** The admin's product editor, saved in one request: details, unlocks and prices together. */
class ProductSaver
{
    private const MAX_UNLOCKS = 25;

    /** @var array<int, bool> tag id => show as a teaser, from this save */
    private array $teasers = [];
    private const MAX_PRICES = 10;

    /** PayPal's longest billing cycle per interval, and a sane cap for the others. */
    private const MAX_INTERVAL_COUNT = ['day' => 365, 'week' => 52, 'month' => 12, 'year' => 1];

    public function __construct(
        private ConnectionInterface $db,
        private Registry $types,
        private TranslatorInterface $translator,
        private PaidTags $paidTags,
        private Gate $gate,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @param bool $prepare make each item sellable now (a member's listing waits for approval)
     */
    public function save(?Product $product, array $data, bool $prepare = true): Product
    {
        $product ??= new Product();
        $isNew = ! $product->exists;

        $name = trim((string) Arr::get($data, 'name', $product->name));
        if ($name === '' || mb_strlen($name) > 150) {
            throw $this->invalid('name', 'name');
        }

        $unlocks = $this->unlocks(Arr::get($data, 'unlocks'));
        $prices = $this->prices(Arr::get($data, 'prices'));

        $this->db->transaction(function () use ($product, $isNew, $name, $data, $unlocks, $prices) {
            $product->name = $name;
            if ($isNew || Arr::has($data, 'slug')) {
                $product->slug = $this->slug((string) Arr::get($data, 'slug', '') ?: $name, $product->id);
            }
            $product->description = $this->text($data, 'description', 5000, $product->description);
            $product->benefits = $this->text($data, 'benefits', 3000, $product->benefits);
            $product->icon = $this->icon(Arr::get($data, 'icon', $product->icon));
            $product->color = $this->color(Arr::get($data, 'color', $product->color));
            $product->is_active = (bool) Arr::get($data, 'active', $isNew ? true : $product->is_active);
            $product->is_hidden = (bool) Arr::get($data, 'hidden', $isNew ? false : $product->is_hidden);
            $groups = array_values(array_unique(array_filter(array_map('intval', (array) Arr::get($data, 'buyerGroupIds', $product->buyerGroupIds())))));
            $product->buyer_group_ids = $groups ? json_encode($groups) : null;
            $stock = Arr::get($data, 'stock', $product->stock);
            $product->stock = $stock === null || $stock === '' ? null : max(0, (int) $stock);
            $product->available_from = $this->date(Arr::get($data, 'availableFrom', $product->available_from?->toIso8601String()));
            $product->available_until = $this->date(Arr::get($data, 'availableUntil', $product->available_until?->toIso8601String()));
            if ($isNew) {
                $product->position = (int) Product::query()->max('position') + 1;
            }
            $product->save();

            if ($unlocks !== null) {
                ProductUnlock::query()->where('product_id', $product->id)->delete();
                foreach ($unlocks as [$type, $id]) {
                    $row = new ProductUnlock();
                    $row->product_id = $product->id;
                    $row->type = $type;
                    $row->target_id = $id;
                    $row->save();
                }
            }

            if ($prices !== null) {
                $this->syncPrices($product, $prices);
            }
        });

        // Make each item sellable (a tag gets its buyers' group, and becomes a
        // teaser or hidden). Outside the transaction: a type's own writes are its own.
        foreach ($prepare ? ($unlocks ?? []) : [] as [$type, $id]) {
            $unlock = $this->types->get($type);
            if ($unlock instanceof TagUnlock) {
                $unlock->setup($id, $this->teasers[$id] ?? null);
            } else {
                $unlock?->prepare($id);
            }
        }

        $this->paidTags->forget();
        $this->gate->forget();
        resolve(Credits::class)->forget();
        resolve(Ads::class)->forget();

        return $product->refresh()->load(['unlocks', 'prices']);
    }

    /**
     * 🚨 A price that anyone has bought keeps its terms forever. Changing what
     * it costs archives it and makes a new one; subscribers stay on the old
     * row, which is what their renewals and receipts say.
     *
     * @param list<array<string, mixed>> $prices
     */
    private function syncPrices(Product $product, array $prices): void
    {
        $current = Price::query()->where('product_id', $product->id)->whereNull('archived_at')->get()->keyBy('id');
        $kept = [];

        foreach ($prices as $position => $data) {
            $existing = isset($data['id']) ? $current->get((int) $data['id']) : null;

            $candidate = $existing ? clone $existing : new Price();
            foreach (['amount_cents', 'currency', 'kind', 'interval', 'interval_count', 'term_days', 'trial_days', 'seats'] as $field) {
                $candidate->{$field} = $data[$field];
            }

            if ($existing && $existing->terms() === $candidate->terms()) {
                $existing->position = $position;
                $existing->save();
                $kept[] = $existing->id;
                continue;
            }

            if ($existing && ! Purchase::query()->where('price_id', $existing->id)->exists()) {
                // Nobody has bought it yet: an edit is just an edit, and the
                // provider plan made for the old terms is forgotten.
                foreach (['amount_cents', 'currency', 'kind', 'interval', 'interval_count', 'term_days', 'trial_days', 'seats'] as $field) {
                    $existing->{$field} = $data[$field];
                }
                $existing->position = $position;
                $existing->save();
                $this->db->table('kiosk_price_refs')->where('price_id', $existing->id)->delete();
                $kept[] = $existing->id;
                continue;
            }

            $price = new Price();
            $price->product_id = $product->id;
            foreach (['amount_cents', 'currency', 'kind', 'interval', 'interval_count', 'term_days', 'trial_days', 'seats'] as $field) {
                $price->{$field} = $data[$field];
            }
            $price->position = $position;
            $price->created_at = Carbon::now();
            $price->save();
            $kept[] = $price->id;
        }

        Price::query()->where('product_id', $product->id)->whereNull('archived_at')->whereNotIn('id', $kept)->update(['archived_at' => Carbon::now()]);
    }

    /** @return list<array{0: string, 1: int}>|null */
    private function unlocks(mixed $raw): ?array
    {
        if ($raw === null) {
            return null;
        }

        $out = [];
        foreach ((array) $raw as $unlock) {
            $type = (string) Arr::get((array) $unlock, 'type');
            $id = (int) Arr::get((array) $unlock, 'targetId');

            if (! $this->types->has($type) || $id <= 0) {
                throw $this->invalid('unlocks', 'unlock_type');
            }

            $out[$type.':'.$id] = [$type, $id];

            if ($type === 'tag' && Arr::has((array) $unlock, 'teaser')) {
                $this->teasers[$id] = (bool) Arr::get((array) $unlock, 'teaser');
            }
        }

        if (! $out) {
            throw $this->invalid('unlocks', 'unlocks_required');
        }
        if (count($out) > self::MAX_UNLOCKS) {
            throw $this->invalid('unlocks', 'too_many_unlocks');
        }

        return array_values($out);
    }

    /** @return list<array<string, mixed>>|null */
    private function prices(mixed $raw): ?array
    {
        if ($raw === null) {
            return null;
        }

        $out = [];
        foreach (array_values((array) $raw) as $price) {
            $price = (array) $price;
            $currency = strtoupper((string) Arr::get($price, 'currency', 'USD'));
            $kind = (string) Arr::get($price, 'kind');
            $amount = Arr::has($price, 'amount') ? Money::toMinor(Arr::get($price, 'amount'), $currency) : (int) Arr::get($price, 'amountCents', 0);

            if (! in_array($currency, Money::CURRENCIES, true)) {
                throw $this->invalid('prices', 'currency');
            }
            if (! in_array($kind, Price::KINDS, true)) {
                throw $this->invalid('prices', 'kind');
            }
            if ($amount === null || $amount < 1 || $amount > 100_000_000) {
                throw $this->invalid('prices', 'amount');
            }

            $interval = null;
            $count = 1;
            $term = null;
            $trial = 0;

            if ($kind === Price::RECURRING) {
                $interval = (string) Arr::get($price, 'interval', 'month');
                $count = max(1, (int) Arr::get($price, 'intervalCount', 1));
                $trial = max(0, (int) Arr::get($price, 'trialDays', 0));
                if (! isset(self::MAX_INTERVAL_COUNT[$interval]) || $count > self::MAX_INTERVAL_COUNT[$interval]) {
                    throw $this->invalid('prices', 'interval');
                }
                if ($trial > 365) {
                    throw $this->invalid('prices', 'trial');
                }
            } elseif ($kind === Price::FIXED_TERM) {
                $term = (int) Arr::get($price, 'termDays', 0);
                if ($term < 1 || $term > 3650) {
                    throw $this->invalid('prices', 'term');
                }
            }

            $out[] = array_filter(['id' => Arr::get($price, 'id')], fn ($v) => $v !== null) + [
                'amount_cents' => $amount,
                'currency' => $currency,
                'kind' => $kind,
                'interval' => $interval,
                'interval_count' => $count,
                'term_days' => $term,
                'trial_days' => $trial,
                // A team plan: the buyer gives the other seats to members.
                'seats' => $kind === Price::CUSTOM ? 1 : min(1000, max(1, (int) Arr::get($price, 'seats', 1))),
            ];
        }

        if (! $out) {
            throw $this->invalid('prices', 'prices_required');
        }
        if (count($out) > self::MAX_PRICES) {
            throw $this->invalid('prices', 'too_many_prices');
        }

        return $out;
    }

    private function slug(string $from, ?int $exceptId): string
    {
        $base = Str::slug($from) ?: 'product';
        $base = mb_substr($base, 0, 160);
        $slug = $base;
        $n = 2;

        while (Product::query()->where('slug', $slug)->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }

    /** @param array<string, mixed> $data */
    private function text(array $data, string $key, int $max, ?string $current): ?string
    {
        if (! Arr::has($data, $key)) {
            return $current;
        }

        $value = trim((string) Arr::get($data, $key));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function icon(mixed $value): ?string
    {
        $value = trim((string) $value);

        return preg_match('/^(fa[a-z]?|fa-[a-z]+)( fa-[a-z0-9-]+)+$/', $value) ? mb_substr($value, 0, 80) : null;
    }

    private function color(mixed $value): ?string
    {
        $value = trim((string) $value);

        return preg_match('/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $value) ? $value : null;
    }

    private function date(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            throw $this->invalid('availableFrom', 'date');
        }
    }

    private function invalid(string $field, string $reason): ValidationException
    {
        return new ValidationException([$field => $this->translator->trans('ernestdefoe-kiosk.admin.errors.'.$reason)]);
    }
}
