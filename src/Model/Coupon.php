<?php

namespace Ernestdefoe\Kiosk\Model;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;

/**
 * @property int $id
 * @property string $code
 * @property string $kind
 * @property int $value
 * @property string|null $currency
 * @property string|null $product_ids
 * @property int|null $max_uses
 * @property int $used_count
 * @property Carbon|null $expires_at
 * @property Carbon $created_at
 */
class Coupon extends AbstractModel
{
    public const PERCENT = 'percent';
    public const AMOUNT = 'amount';

    protected $table = 'kiosk_coupons';

    protected $casts = [
        'value' => 'int',
        'max_uses' => 'int',
        'used_count' => 'int',
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    /** @return list<int> */
    public function productIds(): array
    {
        $ids = json_decode((string) $this->product_ids, true);

        return is_array($ids) ? array_values(array_map('intval', $ids)) : [];
    }

    /** Take one use, if one is left and the code hasn't ended. */
    public function reserve(): bool
    {
        return (bool) static::query()->whereKey($this->id)
            ->where(fn ($q) => $q->whereNull('max_uses')->orWhereColumn('used_count', '<', 'max_uses'))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', Carbon::now()))
            ->increment('used_count');
    }

    /** Give back the use of a checkout that never paid. */
    public static function release(?string $code, int $count = 1): void
    {
        if ($code !== null && $code !== '') {
            static::query()->where('code', $code)->where('used_count', '>=', $count)->decrement('used_count', $count);
        }
    }

    /** What this price costs with the coupon, or null when it doesn't apply. */
    public function apply(Price $price): ?int
    {
        if ($this->expires_at && $this->expires_at->isPast()) {
            return null;
        }
        if ($this->max_uses !== null && $this->used_count >= $this->max_uses) {
            return null;
        }
        if ($this->productIds() && ! in_array($price->product_id, $this->productIds(), true)) {
            return null;
        }
        if ($price->kind === Price::CUSTOM) {
            return null;
        }

        if ($this->kind === self::PERCENT) {
            return (int) round($price->amount_cents * (100 - min(100, max(0, $this->value))) / 100);
        }

        if (strcasecmp((string) $this->currency, $price->currency) !== 0) {
            return null;
        }

        return max(0, $price->amount_cents - $this->value);
    }
}
