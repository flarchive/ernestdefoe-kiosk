<?php

namespace Ernestdefoe\Kiosk\Model;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $product_id
 * @property int $amount_cents
 * @property string $currency
 * @property string $kind
 * @property string|null $interval
 * @property int $interval_count
 * @property int|null $term_days
 * @property int $trial_days
 * @property int $position
 * @property int $seats
 * @property Carbon $created_at
 * @property Carbon|null $archived_at
 * @property-read Product|null $product
 */
class Price extends AbstractModel
{
    public const ONE_OFF = 'one_off';
    public const FIXED_TERM = 'fixed_term';
    public const RECURRING = 'recurring';

    /** One-time, the buyer chooses the amount; amount_cents is the minimum. Tips and bounties. */
    public const CUSTOM = 'custom';

    public const KINDS = [self::ONE_OFF, self::FIXED_TERM, self::RECURRING, self::CUSTOM];
    public const INTERVALS = ['day', 'week', 'month', 'year'];

    protected $table = 'kiosk_prices';

    protected $casts = [
        'product_id' => 'int',
        'amount_cents' => 'int',
        'interval_count' => 'int',
        'term_days' => 'int',
        'trial_days' => 'int',
        'seats' => 'int',
        'position' => 'int',
        'created_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    /** A plain monthly price, to ask a provider whether it takes subscriptions at all. */
    public static function monthly(): self
    {
        $price = new self();
        $price->kind = self::RECURRING;
        $price->interval = 'month';
        $price->interval_count = 1;
        $price->trial_days = 0;

        return $price;
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function isRecurring(): bool
    {
        return $this->kind === self::RECURRING;
    }

    /** The terms a buyer agrees to, compared when deciding whether an edit needs a new row. */
    public function terms(): string
    {
        return implode('|', [$this->amount_cents, strtoupper($this->currency), $this->kind, $this->interval, $this->interval_count, $this->term_days, $this->trial_days, $this->seats ?: 1]);
    }
}
