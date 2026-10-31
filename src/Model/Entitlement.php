<?php

namespace Ernestdefoe\Kiosk\Model;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $purchase_id
 * @property string $type
 * @property int $target_id
 * @property Carbon $starts_at
 * @property Carbon|null $ends_at
 * @property Carbon|null $closed_at
 * @property-read Purchase|null $purchase
 */
class Entitlement extends AbstractModel
{
    protected $table = 'kiosk_entitlements';

    protected $casts = [
        'user_id' => 'int',
        'purchase_id' => 'int',
        'target_id' => 'int',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    /** @return BelongsTo<Purchase, $this> */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    /**
     * Giving access at this moment. Columns are qualified so the scope survives a join.
     *
     * @param Builder<Entitlement> $query
     */
    public function scopeLive(Builder $query, ?Carbon $at = null): void
    {
        $at ??= Carbon::now();

        $query->whereNull('kiosk_entitlements.closed_at')
            ->where('kiosk_entitlements.starts_at', '<=', $at)
            ->where(fn (Builder $q) => $q->whereNull('kiosk_entitlements.ends_at')->orWhere('kiosk_entitlements.ends_at', '>', $at));
    }
}
