<?php

namespace Ernestdefoe\Kiosk\Model;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $number
 * @property int $user_id
 * @property int|null $product_id
 * @property int|null $price_id
 * @property string $product_name
 * @property int $amount_cents
 * @property string $currency
 * @property string $kind
 * @property string|null $interval
 * @property int $interval_count
 * @property int|null $term_days
 * @property string $provider
 * @property string|null $mode
 * @property string|null $provider_ref
 * @property string $status
 * @property Carbon|null $period_start
 * @property Carbon|null $period_end
 * @property bool $cancel_at_period_end
 * @property string|null $note
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $paid_at
 * @property Carbon|null $ended_at
 * @property Carbon|null $reminded_at
 * @property string|null $context_type
 * @property int|null $context_id
 * @property string|null $coupon_code
 * @property int|null $gifted_by
 * @property int $seats
 * @property int|null $seller_id
 * @property int|null $seller_account_id
 * @property-read User|null $user
 * @property-read Product|null $product
 * @property-read Price|null $price
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Payment> $payments
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Entitlement> $entitlements
 */
class Purchase extends AbstractModel
{
    public const PENDING = 'pending';
    public const ACTIVE = 'active';
    /** Will not renew; access runs to period_end. */
    public const CANCELED = 'canceled';
    public const ENDED = 'ended';
    /** Checkout started and never finished. */
    public const EXPIRED = 'expired';
    public const REFUNDED = 'refunded';

    public const GRANT = 'grant';

    protected $table = 'kiosk_purchases';

    public $timestamps = true;

    protected $casts = [
        'user_id' => 'int',
        'product_id' => 'int',
        'price_id' => 'int',
        'amount_cents' => 'int',
        'interval_count' => 'int',
        'term_days' => 'int',
        'gifted_by' => 'int',
        'seats' => 'int',
        'cancel_at_period_end' => 'bool',
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'paid_at' => 'datetime',
        'ended_at' => 'datetime',
        'reminded_at' => 'datetime',
    ];

    /** A new purchase with the price's terms copied in. */
    public static function start(User $user, Price $price, Product $product, string $provider, ?string $mode): self
    {
        $purchase = new self();
        $purchase->number = self::newNumber();
        $purchase->user_id = $user->id;
        $purchase->product_id = $product->id;
        $purchase->price_id = $price->id;
        $purchase->product_name = $product->name;
        $purchase->amount_cents = $price->amount_cents;
        $purchase->currency = strtoupper($price->currency);
        $purchase->kind = $price->kind;
        $purchase->interval = $price->interval;
        $purchase->interval_count = $price->interval_count;
        $purchase->term_days = $price->term_days;
        $purchase->seats = max(1, (int) $price->seats);
        $purchase->provider = $provider;
        $purchase->mode = $mode;
        $purchase->status = self::PENDING;

        return $purchase;
    }

    /** "K-7F3QX9T2M4": short enough to read out, random so numbers say nothing about sales. */
    public static function newNumber(): string
    {
        return 'K-'.strtoupper(Str::random(10));
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Price, $this> */
    public function price(): BelongsTo
    {
        return $this->belongsTo(Price::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return HasMany<Entitlement, $this> */
    public function entitlements(): HasMany
    {
        return $this->hasMany(Entitlement::class);
    }

    public function isRecurring(): bool
    {
        return $this->kind === Price::RECURRING;
    }

    /** Whether it currently gives access. */
    public function isLive(): bool
    {
        return in_array($this->status, [self::ACTIVE, self::CANCELED], true)
            && ($this->period_end === null || $this->period_end->isFuture());
    }

    /** The end of a period that starts at $from, for this purchase's terms. Null = never ends. */
    public function periodEndFrom(Carbon $from): ?Carbon
    {
        return match ($this->kind) {
            Price::FIXED_TERM => $from->copy()->addDays(max(1, (int) $this->term_days)),
            Price::RECURRING => Money::addInterval($from, (string) $this->interval, max(1, $this->interval_count)),
            default => null,
        };
    }
}
