<?php

namespace Ernestdefoe\Kiosk\Model;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $purchase_id
 * @property string $provider
 * @property string $provider_payment_id
 * @property int $amount_cents
 * @property string $currency
 * @property string $status
 * @property int $refunded_cents
 * @property int $fee_cents
 * @property Carbon $created_at
 * @property-read Purchase|null $purchase
 */
class Payment extends AbstractModel
{
    public const COMPLETED = 'completed';
    public const REFUNDED = 'refunded';
    public const REVERSED = 'reversed';

    protected $table = 'kiosk_payments';

    protected $casts = [
        'purchase_id' => 'int',
        'amount_cents' => 'int',
        'refunded_cents' => 'int',
        'created_at' => 'datetime',
    ];

    /** @return BelongsTo<Purchase, $this> */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }
}
